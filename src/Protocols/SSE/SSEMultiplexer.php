<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\SSE;

use Amp\Http\Server\Response;
use HighPerApp\HighPer\Realtime\Protocols\Http3\QuicConnection;
use Psr\Log\LoggerInterface;

/**
 * SSE Multiplexer for Multiple Data Streams
 * 
 * Enables multiple SSE streams over a single HTTP/3 connection
 * with stream isolation, prioritization, and efficient resource usage
 */
class SSEMultiplexer
{
    private LoggerInterface $logger;
    private array $config;
    private array $multiplexedStreams = [];
    private array $streamPriorities = [];
    private array $streamFilters = [];
    private array $streamMetrics = [];
    private int $streamIdCounter = 1;
    
    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    /**
     * Create a new multiplexed SSE stream
     */
    public function createStream(
        QuicConnection $connection,
        string $streamName,
        array $options = []
    ): SSEMultiplexedStream {
        $streamId = $this->generateStreamId();
        $priority = $options['priority'] ?? 'normal';
        $filter = $options['filter'] ?? null;
        
        $stream = new SSEMultiplexedStream(
            $streamId,
            $streamName,
            $connection,
            $this->logger,
            array_merge($this->config, $options)
        );
        
        $this->multiplexedStreams[$streamId] = $stream;
        $this->streamPriorities[$streamId] = $priority;
        $this->streamFilters[$streamId] = $filter;
        $this->streamMetrics[$streamId] = [
            'created_at' => time(),
            'events_sent' => 0,
            'bytes_sent' => 0,
            'last_activity' => time()
        ];
        
        $this->logger->info("Created multiplexed SSE stream", [
            'stream_id' => $streamId,
            'stream_name' => $streamName,
            'priority' => $priority,
            'connection_id' => $connection->getId()
        ]);
        
        return $stream;
    }
    
    /**
     * Send event to specific stream
     */
    public function sendToStream(string $streamId, array $eventData): \Generator
    {
        if (!isset($this->multiplexedStreams[$streamId])) {
            $this->logger->warning("Attempted to send to non-existent stream", ['stream_id' => $streamId]);
            return yield false;
        }
        
        $stream = $this->multiplexedStreams[$streamId];
        
        // Apply stream filter if configured
        if ($this->streamFilters[$streamId] && !$this->applyFilter($streamId, $eventData)) {
            return yield false;
        }
        
        // Add stream metadata to event
        $eventData['stream_id'] = $streamId;
        $eventData['stream_name'] = $stream->getName();
        $eventData['timestamp'] = microtime(true);
        
        $success = yield $stream->sendEvent($eventData);
        
        if ($success) {
            $this->updateStreamMetrics($streamId, $eventData);
        }
        
        return yield $success;
    }
    
    /**
     * Broadcast event to multiple streams
     */
    public function broadcast(array $eventData, array $streamIds = null): \Generator
    {
        $targetStreams = $streamIds ?? array_keys($this->multiplexedStreams);
        $results = [];
        
        // Sort by priority for optimal delivery order
        $prioritizedStreams = $this->sortStreamsByPriority($targetStreams);
        
        foreach ($prioritizedStreams as $streamId) {
            $results[$streamId] = yield $this->sendToStream($streamId, $eventData);
        }
        
        $this->logger->debug("Broadcasted event to multiplexed streams", [
            'event_type' => $eventData['type'] ?? 'unknown',
            'target_streams' => count($targetStreams),
            'successful_sends' => count(array_filter($results))
        ]);
        
        return yield $results;
    }
    
    /**
     * Send different events to different streams simultaneously
     */
    public function multicast(array $streamEvents): \Generator
    {
        $promises = [];
        $results = [];
        
        foreach ($streamEvents as $streamId => $eventData) {
            $promises[$streamId] = async(fn() => yield $this->sendToStream($streamId, $eventData));
        }
        
        // Send all events concurrently
        foreach ($promises as $streamId => $promise) {
            $results[$streamId] = yield $promise;
        }
        
        return yield $results;
    }
    
    /**
     * Create stream group for related streams
     */
    public function createStreamGroup(string $groupName, array $streamIds): SSEStreamGroup
    {
        return new SSEStreamGroup($groupName, $streamIds, $this);
    }
    
    /**
     * Close specific stream
     */
    public function closeStream(string $streamId): \Generator
    {
        if (!isset($this->multiplexedStreams[$streamId])) {
            return yield false;
        }
        
        $stream = $this->multiplexedStreams[$streamId];
        yield $stream->close();
        
        unset($this->multiplexedStreams[$streamId]);
        unset($this->streamPriorities[$streamId]);
        unset($this->streamFilters[$streamId]);
        unset($this->streamMetrics[$streamId]);
        
        $this->logger->info("Closed multiplexed SSE stream", ['stream_id' => $streamId]);
        
        return yield true;
    }
    
    /**
     * Get stream statistics
     */
    public function getStreamMetrics(string $streamId = null): array
    {
        if ($streamId) {
            return $this->streamMetrics[$streamId] ?? [];
        }
        
        return [
            'total_streams' => count($this->multiplexedStreams),
            'active_streams' => count(array_filter(
                $this->multiplexedStreams,
                fn($stream) => $stream->isActive()
            )),
            'streams' => $this->streamMetrics
        ];
    }
    
    /**
     * Set stream priority
     */
    public function setStreamPriority(string $streamId, string $priority): bool
    {
        if (!isset($this->multiplexedStreams[$streamId])) {
            return false;
        }
        
        $this->streamPriorities[$streamId] = $priority;
        return true;
    }
    
    /**
     * Set stream filter
     */
    public function setStreamFilter(string $streamId, callable $filter): bool
    {
        if (!isset($this->multiplexedStreams[$streamId])) {
            return false;
        }
        
        $this->streamFilters[$streamId] = $filter;
        return true;
    }
    
    /**
     * Generate unique stream ID
     */
    private function generateStreamId(): string
    {
        return 'sse_stream_' . $this->streamIdCounter++;
    }
    
    /**
     * Sort streams by priority
     */
    private function sortStreamsByPriority(array $streamIds): array
    {
        $priorityOrder = ['critical' => 1, 'high' => 2, 'normal' => 3, 'low' => 4];
        
        usort($streamIds, function($a, $b) use ($priorityOrder) {
            $priorityA = $priorityOrder[$this->streamPriorities[$a] ?? 'normal'];
            $priorityB = $priorityOrder[$this->streamPriorities[$b] ?? 'normal'];
            
            return $priorityA <=> $priorityB;
        });
        
        return $streamIds;
    }
    
    /**
     * Apply stream filter
     */
    private function applyFilter(string $streamId, array $eventData): bool
    {
        $filter = $this->streamFilters[$streamId];
        
        if (is_callable($filter)) {
            return $filter($eventData);
        }
        
        return true;
    }
    
    /**
     * Update stream metrics
     */
    private function updateStreamMetrics(string $streamId, array $eventData): void
    {
        if (!isset($this->streamMetrics[$streamId])) {
            return;
        }
        
        $this->streamMetrics[$streamId]['events_sent']++;
        $this->streamMetrics[$streamId]['bytes_sent'] += strlen(json_encode($eventData));
        $this->streamMetrics[$streamId]['last_activity'] = time();
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'max_streams_per_connection' => 50,
            'stream_idle_timeout' => 300, // 5 minutes
            'priority_enabled' => true,
            'filter_enabled' => true,
            'metrics_enabled' => true
        ];
    }
}

/**
 * Individual multiplexed SSE stream
 */
class SSEMultiplexedStream
{
    private string $id;
    private string $name;
    private QuicConnection $connection;
    private LoggerInterface $logger;
    private array $config;
    private bool $active = true;
    private float $lastActivity;
    
    public function __construct(
        string $id,
        string $name,
        QuicConnection $connection,
        LoggerInterface $logger,
        array $config
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->connection = $connection;
        $this->logger = $logger;
        $this->config = $config;
        $this->lastActivity = microtime(true);
    }
    
    public function getId(): string
    {
        return $this->id;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function isActive(): bool
    {
        return $this->active && $this->connection->isAlive();
    }
    
    public function sendEvent(array $eventData): \Generator
    {
        if (!$this->isActive()) {
            return yield false;
        }
        
        try {
            // Format SSE event with stream identification
            $sseData = $this->formatSSEEvent($eventData);
            
            // Send over HTTP/3 stream
            yield $this->connection->writeToStream($this->id, $sseData);
            
            $this->lastActivity = microtime(true);
            
            return yield true;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to send SSE event to stream", [
                'stream_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            
            return yield false;
        }
    }
    
    public function close(): \Generator
    {
        $this->active = false;
        
        try {
            yield $this->connection->closeStream($this->id);
        } catch (\Throwable $e) {
            $this->logger->warning("Error closing SSE stream", [
                'stream_id' => $this->id,
                'error' => $e->getMessage()
            ]);
        }
        
        return yield;
    }
    
    private function formatSSEEvent(array $eventData): string
    {
        $sse = '';
        
        if (isset($eventData['id'])) {
            $sse .= "id: {$eventData['id']}\n";
        }
        
        if (isset($eventData['event'])) {
            $sse .= "event: {$eventData['event']}\n";
        }
        
        if (isset($eventData['retry'])) {
            $sse .= "retry: {$eventData['retry']}\n";
        }
        
        // Add stream metadata
        $sse .= "data: " . json_encode([
            'stream_id' => $this->id,
            'stream_name' => $this->name,
            'timestamp' => $eventData['timestamp'],
            'payload' => $eventData['data'] ?? $eventData
        ]) . "\n\n";
        
        return $sse;
    }
}

/**
 * Stream group for managing related streams
 */
class SSEStreamGroup
{
    private string $name;
    private array $streamIds;
    private SSEMultiplexer $multiplexer;
    
    public function __construct(string $name, array $streamIds, SSEMultiplexer $multiplexer)
    {
        $this->name = $name;
        $this->streamIds = $streamIds;
        $this->multiplexer = $multiplexer;
    }
    
    public function broadcast(array $eventData): \Generator
    {
        return yield $this->multiplexer->broadcast($eventData, $this->streamIds);
    }
    
    public function addStream(string $streamId): void
    {
        if (!in_array($streamId, $this->streamIds)) {
            $this->streamIds[] = $streamId;
        }
    }
    
    public function removeStream(string $streamId): void
    {
        $this->streamIds = array_filter($this->streamIds, fn($id) => $id !== $streamId);
    }
    
    public function getStreams(): array
    {
        return $this->streamIds;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
}