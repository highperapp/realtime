<?php

declare(strict_types=1);

namespace EaseAppPHP\HighPer\Realtime\Protocols\SSE;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use EaseAppPHP\HighPer\Realtime\Protocols\ProtocolInterface;
use EaseAppPHP\HighPer\Realtime\Protocols\Http3\Http3Server;
use EaseAppPHP\HighPer\Realtime\Protocols\Http3\QuicConnection;
use Psr\Log\LoggerInterface;

/**
 * Server-Sent Events over HTTP/3 Implementation
 * 
 * Next-generation SSE implementation using HTTP/3 and QUIC
 * with multiplexing, 0-RTT resumption, and enhanced streaming
 */
class SSEHttp3Protocol implements ProtocolInterface
{
    private LoggerInterface $logger;
    private array $config;
    private Http3Server $http3Server;
    private SSEStreamManager $streamManager;
    private SSEEventMultiplexer $eventMultiplexer;
    private SSECompressionManager $compressionManager;
    
    private array $connections = [];
    private array $channels = [];
    private array $eventStreams = [];
    private array $eventHandlers = [];
    private bool $isRunning = false;
    private array $metrics = [];

    public function __construct(LoggerInterface $logger, array $config, Http3Server $http3Server)
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->http3Server = $http3Server;
        
        $this->initializeComponents();
        $this->initializeMetrics();
    }

    /**
     * Initialize SSE over HTTP/3 components
     */
    private function initializeComponents(): void
    {
        $this->streamManager = new SSEStreamManager($this->logger, $this->config);
        $this->eventMultiplexer = new SSEEventMultiplexer($this->logger, $this->config);
        $this->compressionManager = new SSECompressionManager($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'connections' => 0,
            'active_connections' => 0,
            'events_sent' => 0,
            'bytes_sent' => 0,
            'streams_created' => 0,
            'active_streams' => 0,
            'multiplexed_events' => 0,
            'compression_ratio' => 0,
            'reconnections' => 0,
            'zero_rtt_connections' => 0,
            'average_event_size' => 0,
            'events_per_second' => 0
        ];
    }

    /**
     * Check if request supports SSE over HTTP/3
     */
    public function supports(Request $request): bool
    {
        $accept = $request->getHeader('accept') ?? '';
        $cacheControl = $request->getHeader('cache-control') ?? '';
        
        // Check for SSE headers
        $supportsSSE = strpos($accept, 'text/event-stream') !== false ||
                      strpos($cacheControl, 'no-cache') !== false ||
                      $request->hasHeader('last-event-id');

        // Check if client supports HTTP/3
        $supportsHttp3 = $this->http3Server->supports($request);

        return $supportsSSE && $supportsHttp3;
    }

    /**
     * Handle SSE handshake over HTTP/3
     */
    public function handleHandshake(Request $request): \Generator
    {
        try {
            // Extract stream parameters
            $streamId = $this->extractStreamId($request);
            $lastEventId = $request->getHeader('last-event-id') ?? '0';
            $channels = $this->extractChannels($request);
            
            // Get or create QUIC connection
            $quicConnection = yield $this->getOrCreateQuicConnection($request);
            
            // Create SSE stream over HTTP/3
            $sseStream = yield $quicConnection->createUnidirectionalStream();
            
            // Create SSE connection wrapper
            $sseConnection = new SSEHttp3Connection(
                $this->generateConnectionId(),
                $quicConnection,
                $sseStream,
                $request,
                $this->logger,
                $this->config
            );

            // Configure stream for SSE
            yield $this->configureSSEStream($sseConnection, $lastEventId, $channels);

            // Store connection
            $connectionId = $sseConnection->getId();
            $this->connections[$connectionId] = $sseConnection;
            $this->metrics['connections']++;
            $this->metrics['active_connections']++;

            // Check for 0-RTT
            if ($quicConnection->is0RTTConnection()) {
                $this->metrics['zero_rtt_connections']++;
            }

            // Setup connection handlers
            yield $this->setupConnectionHandlers($sseConnection);

            // Add to channels
            foreach ($channels as $channel) {
                yield $this->joinChannel($connectionId, $channel);
            }

            // Send SSE handshake response
            $response = new Response(200, [
                'content-type' => 'text/event-stream',
                'cache-control' => 'no-cache',
                'access-control-allow-origin' => '*',
                'access-control-allow-headers' => 'Last-Event-ID',
                'x-transport' => 'sse-http3',
                'x-compression' => $this->config['enable_compression'] ? 'gzip' : 'none',
                'x-multiplexing' => 'enabled'
            ]);

            // Send initial connection event
            yield $this->sendConnectionEvent($sseConnection);

            // Send missed events if resuming
            if ($lastEventId !== '0') {
                yield $this->sendMissedEvents($sseConnection, $lastEventId);
            }

            $this->logger->info('SSE over HTTP/3 connection established', [
                'connection_id' => $connectionId,
                'stream_id' => $streamId,
                'channels' => $channels,
                'last_event_id' => $lastEventId,
                'is_0rtt' => $quicConnection->is0RTTConnection()
            ]);

            // Trigger connect event
            yield $this->triggerEvent('connect', $sseConnection);

            return $response;
            
        } catch (\Throwable $e) {
            $this->logger->error('SSE HTTP/3 handshake failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            return new Response(500, [], 'SSE handshake failed');
        }
    }

    /**
     * Get or create QUIC connection for SSE
     */
    private function getOrCreateQuicConnection(Request $request): \Generator
    {
        $remoteAddress = $request->getClient()->getRemoteAddress();
        
        // Try to reuse existing HTTP/3 connection
        $existingConnection = $this->http3Server->getConnectionByAddress($remoteAddress);
        
        if ($existingConnection && $existingConnection->isActive()) {
            return $existingConnection;
        }

        // Create new HTTP/3 connection optimized for SSE
        return yield $this->http3Server->createConnection($remoteAddress, [
            'enable_sse' => true,
            'enable_compression' => $this->config['enable_compression'],
            'stream_priority' => 'high'
        ]);
    }

    /**
     * Configure SSE stream
     */
    private function configureSSEStream(SSEHttp3Connection $connection, string $lastEventId, array $channels): \Generator
    {
        // Set stream properties
        yield $connection->configureStream([
            'last_event_id' => $lastEventId,
            'channels' => $channels,
            'compression' => $this->config['enable_compression'],
            'keep_alive_interval' => $this->config['keep_alive_interval'],
            'retry_interval' => $this->config['retry_interval']
        ]);

        // Enable multiplexing if configured
        if ($this->config['enable_multiplexing']) {
            yield $connection->enableMultiplexing();
        }
    }

    /**
     * Setup SSE connection handlers
     */
    private function setupConnectionHandlers(SSEHttp3Connection $connection): \Generator
    {
        // Handle connection close
        $connection->onClose(function($reason) use ($connection) {
            yield $this->handleConnectionClose($connection, $reason);
        });

        // Handle connection errors
        $connection->onError(function($error) use ($connection) {
            yield $this->handleConnectionError($connection, $error);
        });

        // Handle stream events
        $connection->onStreamEvent(function($event) use ($connection) {
            yield $this->handleStreamEvent($connection, $event);
        });

        // Start keep-alive
        yield $this->startKeepAlive($connection);
    }

    /**
     * Send message (SSE event) to specific connection
     */
    public function sendMessage(string $connectionId, array $data): \Generator
    {
        if (!isset($this->connections[$connectionId])) {
            throw new \InvalidArgumentException("Connection {$connectionId} not found");
        }

        $connection = $this->connections[$connectionId];
        
        // Create SSE event
        $event = new SSEEvent([
            'id' => $this->generateEventId(),
            'type' => $data['type'] ?? 'message',
            'data' => $data['data'] ?? $data,
            'retry' => $data['retry'] ?? null,
            'timestamp' => microtime(true)
        ]);

        // Send event
        yield $this->sendEvent($connection, $event);
    }

    /**
     * Send SSE event to connection
     */
    private function sendEvent(SSEHttp3Connection $connection, SSEEvent $event): \Generator
    {
        try {
            // Format SSE event
            $formattedEvent = $this->formatSSEEvent($event);
            
            // Apply compression if enabled
            if ($this->config['enable_compression']) {
                $formattedEvent = yield $this->compressionManager->compress($formattedEvent);
            }

            // Send over HTTP/3 stream
            yield $connection->write($formattedEvent);

            // Update metrics
            $this->metrics['events_sent']++;
            $this->metrics['bytes_sent'] += strlen($formattedEvent);
            $this->updateAverageEventSize(strlen($formattedEvent));

            $this->logger->debug('SSE event sent over HTTP/3', [
                'connection_id' => $connection->getId(),
                'event_id' => $event->getId(),
                'event_type' => $event->getType(),
                'size' => strlen($formattedEvent),
                'compressed' => $this->config['enable_compression']
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send SSE event', [
                'connection_id' => $connection->getId(),
                'event_id' => $event->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send multiplexed event to multiple streams
     */
    public function sendMultiplexedEvent(array $connectionIds, array $eventData): \Generator
    {
        if (empty($connectionIds)) {
            return;
        }

        // Create event
        $event = new SSEEvent([
            'id' => $this->generateEventId(),
            'type' => $eventData['type'] ?? 'message',
            'data' => $eventData['data'] ?? $eventData,
            'timestamp' => microtime(true)
        ]);

        // Use multiplexer for efficient delivery
        yield $this->eventMultiplexer->sendToMultiple($connectionIds, $event, $this->connections);
        
        $this->metrics['multiplexed_events']++;
    }

    /**
     * Broadcast to multiple connections
     */
    public function broadcast(array $connectionIds, array $data): \Generator
    {
        yield $this->sendMultiplexedEvent($connectionIds, $data);
    }

    /**
     * Broadcast to channel
     */
    public function broadcastToChannel(string $channel, array $data): \Generator
    {
        $connectionIds = $this->getChannelConnections($channel);
        yield $this->broadcast($connectionIds, $data);
    }

    /**
     * Join connection to channel
     */
    public function joinChannel(string $connectionId, string $channel): \Generator
    {
        if (!isset($this->connections[$connectionId])) {
            throw new \InvalidArgumentException("Connection {$connectionId} not found");
        }

        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }

        $this->channels[$channel][$connectionId] = true;
        $this->connections[$connectionId]->addChannel($channel);
        
        // Send channel join event
        yield $this->sendMessage($connectionId, [
            'type' => 'channel_joined',
            'data' => ['channel' => $channel, 'timestamp' => time()]
        ]);

        $this->logger->debug('Connection joined SSE channel', [
            'connection_id' => $connectionId,
            'channel' => $channel,
            'channel_size' => count($this->channels[$channel])
        ]);
    }

    /**
     * Leave channel
     */
    public function leaveChannel(string $connectionId, string $channel): \Generator
    {
        if (isset($this->channels[$channel][$connectionId])) {
            unset($this->channels[$channel][$connectionId]);
            
            if (empty($this->channels[$channel])) {
                unset($this->channels[$channel]);
            }
        }

        if (isset($this->connections[$connectionId])) {
            $this->connections[$connectionId]->removeChannel($channel);
            
            // Send channel leave event
            yield $this->sendMessage($connectionId, [
                'type' => 'channel_left',
                'data' => ['channel' => $channel, 'timestamp' => time()]
            ]);
        }
    }

    /**
     * Close connection
     */
    public function closeConnection(string $connectionId, int $code = 1000, string $reason = ''): \Generator
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }

        $connection = $this->connections[$connectionId];
        yield $connection->close($reason);
    }

    /**
     * Handle connection close
     */
    private function handleConnectionClose(SSEHttp3Connection $connection, string $reason): \Generator
    {
        $connectionId = $connection->getId();
        
        // Remove from channels
        foreach ($connection->getChannels() as $channel) {
            yield $this->leaveChannel($connectionId, $channel);
        }

        // Remove connection
        unset($this->connections[$connectionId]);
        $this->metrics['active_connections']--;
        
        // Trigger disconnect event
        yield $this->triggerEvent('disconnect', $connection, $reason);
        
        $this->logger->info('SSE HTTP/3 connection closed', [
            'connection_id' => $connectionId,
            'reason' => $reason
        ]);
    }

    /**
     * Start SSE over HTTP/3 server
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting SSE over HTTP/3 server');

        // Start HTTP/3 server if not already running
        if (!$this->http3Server->isRunning()) {
            yield $this->http3Server->start();
        }

        $this->isRunning = true;
        
        // Start maintenance tasks
        \Amp\async(function() {
            yield $this->startMaintenanceTasks();
        });

        $this->logger->info('SSE over HTTP/3 server started');
    }

    /**
     * Stop server
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping SSE over HTTP/3 server');

        // Close all connections
        $closeFutures = [];
        foreach ($this->connections as $connection) {
            $closeFutures[] = $this->closeConnection($connection->getId());
        }

        if (!empty($closeFutures)) {
            yield \Amp\Future::awaitAll($closeFutures);
        }

        $this->isRunning = false;
        
        $this->logger->info('SSE over HTTP/3 server stopped');
    }

    /**
     * Get protocol name
     */
    public function getName(): string
    {
        return 'SSE-HTTP3';
    }

    /**
     * Get protocol version
     */
    public function getVersion(): string
    {
        return 'EventSource-over-HTTP3';
    }

    /**
     * Get connection count
     */
    public function getConnectionCount(): int
    {
        return $this->metrics['active_connections'];
    }

    /**
     * Get connections in channel
     */
    public function getChannelConnections(string $channel): array
    {
        return array_keys($this->channels[$channel] ?? []);
    }

    /**
     * Get metrics
     */
    public function getMetrics(): array
    {
        // Update dynamic metrics
        $this->metrics['active_connections'] = count($this->connections);
        $this->metrics['active_streams'] = count($this->eventStreams);
        
        // Calculate events per second
        static $lastEventCount = 0;
        static $lastTime = 0;
        
        $currentTime = time();
        if ($lastTime > 0 && $currentTime > $lastTime) {
            $this->metrics['events_per_second'] = 
                ($this->metrics['events_sent'] - $lastEventCount) / ($currentTime - $lastTime);
        }
        
        $lastEventCount = $this->metrics['events_sent'];
        $lastTime = $currentTime;

        return $this->metrics;
    }

    /**
     * Register event handlers
     */
    public function onConnect(callable $handler): void
    {
        $this->eventHandlers['connect'][] = $handler;
    }

    public function onDisconnect(callable $handler): void
    {
        $this->eventHandlers['disconnect'][] = $handler;
    }

    public function onMessage(callable $handler): void
    {
        $this->eventHandlers['message'][] = $handler;
    }

    public function onError(callable $handler): void
    {
        $this->eventHandlers['error'][] = $handler;
    }

    /**
     * Helper methods
     */
    private function extractStreamId(Request $request): string
    {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        return $params['stream'] ?? 'default';
    }

    private function extractChannels(Request $request): array
    {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        
        if (isset($params['channels'])) {
            return explode(',', $params['channels']);
        }
        
        return ['default'];
    }

    private function generateConnectionId(): string
    {
        return 'sse-h3-' . bin2hex(random_bytes(8));
    }

    private function generateEventId(): string
    {
        return time() . '-' . bin2hex(random_bytes(4));
    }

    private function formatSSEEvent(SSEEvent $event): string
    {
        $output = '';
        
        if ($event->getId()) {
            $output .= "id: {$event->getId()}\n";
        }
        
        if ($event->getType() && $event->getType() !== 'message') {
            $output .= "event: {$event->getType()}\n";
        }
        
        if ($event->getRetry()) {
            $output .= "retry: {$event->getRetry()}\n";
        }
        
        $data = $event->getData();
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }
        
        // Handle multi-line data
        $lines = explode("\n", (string)$data);
        foreach ($lines as $line) {
            $output .= "data: {$line}\n";
        }
        
        $output .= "\n"; // End event with blank line
        
        return $output;
    }

    private function sendConnectionEvent(SSEHttp3Connection $connection): \Generator
    {
        yield $this->sendEvent($connection, new SSEEvent([
            'id' => $this->generateEventId(),
            'type' => 'connection',
            'data' => [
                'connected' => true,
                'connection_id' => $connection->getId(),
                'transport' => 'http3',
                'features' => [
                    'multiplexing' => $this->config['enable_multiplexing'],
                    'compression' => $this->config['enable_compression'],
                    '0rtt' => $connection->getQuicConnection()->is0RTTConnection()
                ]
            ]
        ]));
    }

    private function sendMissedEvents(SSEHttp3Connection $connection, string $lastEventId): \Generator
    {
        // This would typically query an event store or cache
        // For now, just send a reconnection acknowledgment
        yield $this->sendEvent($connection, new SSEEvent([
            'id' => $this->generateEventId(),
            'type' => 'reconnection',
            'data' => [
                'last_event_id' => $lastEventId,
                'reconnected_at' => time()
            ]
        ]));
        
        $this->metrics['reconnections']++;
    }

    private function startKeepAlive(SSEHttp3Connection $connection): \Generator
    {
        $interval = $this->config['keep_alive_interval'];
        
        \Amp\async(function() use ($connection, $interval) {
            while ($connection->isActive()) {
                yield \Amp\delay($interval * 1000);
                
                try {
                    // Send comment as keep-alive
                    yield $connection->write(": keepalive\n\n");
                } catch (\Throwable $e) {
                    break; // Connection closed
                }
            }
        });
    }

    private function updateAverageEventSize(int $size): void
    {
        if ($this->metrics['average_event_size'] === 0) {
            $this->metrics['average_event_size'] = $size;
        } else {
            $alpha = 0.1;
            $this->metrics['average_event_size'] = 
                (1 - $alpha) * $this->metrics['average_event_size'] + $alpha * $size;
        }
    }

    private function triggerEvent(string $event, ...$args): \Generator
    {
        if (!isset($this->eventHandlers[$event])) {
            return;
        }

        foreach ($this->eventHandlers[$event] as $handler) {
            try {
                yield $handler(...$args);
            } catch (\Throwable $e) {
                $this->logger->error('Error in event handler', [
                    'event' => $event,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function startMaintenanceTasks(): \Generator
    {
        while ($this->isRunning) {
            yield \Amp\delay(60000); // 1 minute
            
            try {
                // Cleanup dead connections
                yield $this->cleanupDeadConnections();
                
                // Update compression statistics
                if ($this->config['enable_compression']) {
                    yield $this->updateCompressionMetrics();
                }
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in SSE maintenance tasks', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function cleanupDeadConnections(): \Generator
    {
        $deadConnections = [];
        
        foreach ($this->connections as $connectionId => $connection) {
            if (!$connection->isActive()) {
                $deadConnections[] = $connectionId;
            }
        }

        foreach ($deadConnections as $connectionId) {
            yield $this->closeConnection($connectionId, 1006, 'Connection timeout');
        }

        if (!empty($deadConnections)) {
            $this->logger->debug('Cleaned up dead SSE connections', [
                'count' => count($deadConnections)
            ]);
        }
    }

    private function updateCompressionMetrics(): \Generator
    {
        $stats = yield $this->compressionManager->getStatistics();
        $this->metrics['compression_ratio'] = $stats['compression_ratio'] ?? 0;
    }

    private function handleConnectionError(SSEHttp3Connection $connection, \Throwable $error): \Generator
    {
        $this->logger->error('SSE HTTP/3 connection error', [
            'connection_id' => $connection->getId(),
            'error' => $error->getMessage()
        ]);
        
        yield $this->triggerEvent('error', $connection, $error);
    }

    private function handleStreamEvent(SSEHttp3Connection $connection, array $event): \Generator
    {
        // Handle stream-specific events (flow control, etc.)
        $this->logger->debug('SSE stream event', [
            'connection_id' => $connection->getId(),
            'event_type' => $event['type'] ?? 'unknown'
        ]);
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enable_compression' => true,
            'enable_multiplexing' => true,
            'keep_alive_interval' => 30, // seconds
            'retry_interval' => 3000, // milliseconds
            'max_connections' => 10000,
            'compression_level' => 6,
            'event_buffer_size' => 1000,
            'stream_priority' => 'high',
            'enable_0rtt' => true
        ];
    }
}