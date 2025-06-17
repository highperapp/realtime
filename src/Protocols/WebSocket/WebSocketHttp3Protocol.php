<?php

declare(strict_types=1);

namespace EaseAppPHP\HighPer\Realtime\Protocols\WebSocket;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use EaseAppPHP\HighPer\Realtime\Protocols\ProtocolInterface;
use EaseAppPHP\HighPer\Realtime\Protocols\Http3\QuicConnection;
use EaseAppPHP\HighPer\Realtime\Protocols\Http3\Http3Server;
use Psr\Log\LoggerInterface;

/**
 * WebSocket over HTTP/3 Implementation
 * 
 * Next-generation WebSocket implementation using HTTP/3 and QUIC transport
 * for improved performance, multiplexing, and connection resilience
 */
class WebSocketHttp3Protocol implements ProtocolInterface
{
    private LoggerInterface $logger;
    private array $config;
    private Http3Server $http3Server;
    private WebSocketFrameProcessor $frameProcessor;
    private WebSocketConnectionManager $connectionManager;
    
    private array $connections = [];
    private array $channels = [];
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
     * Initialize WebSocket over HTTP/3 components
     */
    private function initializeComponents(): void
    {
        $this->frameProcessor = new WebSocketFrameProcessor($this->logger, $this->config);
        $this->connectionManager = new WebSocketConnectionManager($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'connections' => 0,
            'active_connections' => 0,
            'messages_sent' => 0,
            'messages_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'frames_sent' => 0,
            'frames_received' => 0,
            'ping_pong_cycles' => 0,
            'connection_upgrades' => 0,
            'http3_streams' => 0,
            'average_frame_size' => 0
        ];
    }

    /**
     * Check if request supports WebSocket over HTTP/3
     */
    public function supports(Request $request): bool
    {
        $upgrade = strtolower($request->getHeader('upgrade') ?? '');
        $connection = strtolower($request->getHeader('connection') ?? '');
        $wsVersion = $request->getHeader('sec-websocket-version') ?? '';
        $wsKey = $request->getHeader('sec-websocket-key') ?? '';
        
        // Check for WebSocket upgrade headers
        $hasWebSocketHeaders = $upgrade === 'websocket' && 
                              strpos($connection, 'upgrade') !== false &&
                              !empty($wsKey) && 
                              $wsVersion === '13';

        // Check if client supports HTTP/3
        $supportsHttp3 = $this->http3Server->supports($request);

        return $hasWebSocketHeaders && $supportsHttp3;
    }

    /**
     * Handle WebSocket handshake over HTTP/3
     */
    public function handleHandshake(Request $request): \Generator
    {
        try {
            // Validate WebSocket headers
            $wsKey = $request->getHeader('sec-websocket-key');
            $wsVersion = $request->getHeader('sec-websocket-version');
            $wsProtocol = $request->getHeader('sec-websocket-protocol');
            
            if (empty($wsKey) || $wsVersion !== '13') {
                return new Response(400, [], 'Invalid WebSocket headers');
            }

            // Generate WebSocket accept key
            $acceptKey = base64_encode(sha1($wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            
            // Get HTTP/3 connection
            $quicConnection = yield $this->getOrCreateQuicConnection($request);
            
            // Create WebSocket stream over HTTP/3
            $wsStream = yield $quicConnection->createBidirectionalStream();
            
            // Create WebSocket connection wrapper
            $wsConnection = new WebSocketHttp3Connection(
                $this->generateConnectionId(),
                $quicConnection,
                $wsStream,
                $request,
                $this->logger,
                $this->config
            );

            // Store connection
            $connectionId = $wsConnection->getId();
            $this->connections[$connectionId] = $wsConnection;
            $this->metrics['connections']++;
            $this->metrics['active_connections']++;
            $this->metrics['connection_upgrades']++;

            // Setup connection handlers
            yield $this->setupConnectionHandlers($wsConnection);

            // Send WebSocket handshake response over HTTP/3
            $response = new Response(101, [
                'upgrade' => 'websocket',
                'connection' => 'upgrade',
                'sec-websocket-accept' => $acceptKey,
                'sec-websocket-protocol' => $wsProtocol ?? '',
                'x-transport' => 'http3-quic',
                'x-websocket-extensions' => 'permessage-deflate'
            ]);

            $this->logger->info('WebSocket over HTTP/3 handshake completed', [
                'connection_id' => $connectionId,
                'protocol' => $wsProtocol,
                'remote_address' => (string)$request->getClient()->getRemoteAddress()
            ]);

            // Trigger connect event
            yield $this->triggerEvent('connect', $wsConnection);

            return $response;
            
        } catch (\Throwable $e) {
            $this->logger->error('WebSocket HTTP/3 handshake failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            return new Response(500, [], 'WebSocket handshake failed');
        }
    }

    /**
     * Get or create QUIC connection for WebSocket
     */
    private function getOrCreateQuicConnection(Request $request): \Generator
    {
        $remoteAddress = $request->getClient()->getRemoteAddress();
        
        // Try to reuse existing HTTP/3 connection
        $existingConnection = $this->http3Server->getConnectionByAddress($remoteAddress);
        
        if ($existingConnection && $existingConnection->isActive()) {
            return $existingConnection;
        }

        // Create new HTTP/3 connection
        return yield $this->http3Server->createConnection($remoteAddress, [
            'enable_websocket' => true,
            'websocket_compression' => $this->config['enable_compression']
        ]);
    }

    /**
     * Setup WebSocket connection event handlers
     */
    private function setupConnectionHandlers(WebSocketHttp3Connection $connection): \Generator
    {
        // Handle incoming WebSocket frames
        $connection->onFrame(function($frame) use ($connection) {
            yield $this->handleWebSocketFrame($connection, $frame);
        });

        // Handle connection close
        $connection->onClose(function($code, $reason) use ($connection) {
            yield $this->handleConnectionClose($connection, $code, $reason);
        });

        // Handle connection errors
        $connection->onError(function($error) use ($connection) {
            yield $this->handleConnectionError($connection, $error);
        });

        // Start frame processing
        yield $connection->startFrameProcessing();
    }

    /**
     * Handle incoming WebSocket frame
     */
    private function handleWebSocketFrame(WebSocketHttp3Connection $connection, WebSocketFrame $frame): \Generator
    {
        $this->metrics['frames_received']++;
        $this->metrics['bytes_received'] += $frame->getPayloadLength();
        
        try {
            switch ($frame->getOpcode()) {
                case WebSocketFrame::OPCODE_TEXT:
                case WebSocketFrame::OPCODE_BINARY:
                    yield $this->handleDataFrame($connection, $frame);
                    break;
                    
                case WebSocketFrame::OPCODE_PING:
                    yield $this->handlePingFrame($connection, $frame);
                    break;
                    
                case WebSocketFrame::OPCODE_PONG:
                    yield $this->handlePongFrame($connection, $frame);
                    break;
                    
                case WebSocketFrame::OPCODE_CLOSE:
                    yield $this->handleCloseFrame($connection, $frame);
                    break;
                    
                default:
                    $this->logger->warning('Unknown WebSocket frame opcode', [
                        'opcode' => $frame->getOpcode(),
                        'connection_id' => $connection->getId()
                    ]);
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Error handling WebSocket frame', [
                'error' => $e->getMessage(),
                'connection_id' => $connection->getId(),
                'opcode' => $frame->getOpcode()
            ]);
        }
    }

    /**
     * Handle data frame (text/binary)
     */
    private function handleDataFrame(WebSocketHttp3Connection $connection, WebSocketFrame $frame): \Generator
    {
        $payload = $frame->getPayload();
        $this->metrics['messages_received']++;
        
        // Update average frame size
        $this->updateAverageFrameSize($frame->getPayloadLength());
        
        // Trigger message event
        yield $this->triggerEvent('message', $connection, $payload, $frame->getOpcode());
    }

    /**
     * Handle ping frame
     */
    private function handlePingFrame(WebSocketHttp3Connection $connection, WebSocketFrame $frame): \Generator
    {
        // Send pong response
        $pongFrame = new WebSocketFrame(
            WebSocketFrame::OPCODE_PONG,
            $frame->getPayload()
        );
        
        yield $connection->sendFrame($pongFrame);
        $this->metrics['ping_pong_cycles']++;
        
        $this->logger->debug('Ping-pong cycle completed', [
            'connection_id' => $connection->getId()
        ]);
    }

    /**
     * Handle pong frame
     */
    private function handlePongFrame(WebSocketHttp3Connection $connection, WebSocketFrame $frame): \Generator
    {
        $connection->updateLastPong();
        $this->logger->debug('Pong received', [
            'connection_id' => $connection->getId()
        ]);
    }

    /**
     * Handle close frame
     */
    private function handleCloseFrame(WebSocketHttp3Connection $connection, WebSocketFrame $frame): \Generator
    {
        $payload = $frame->getPayload();
        $code = 1000; // Normal closure
        $reason = '';
        
        if (strlen($payload) >= 2) {
            $code = unpack('n', substr($payload, 0, 2))[1];
            $reason = substr($payload, 2);
        }
        
        yield $connection->close($code, $reason);
    }

    /**
     * Send message to specific connection
     */
    public function sendMessage(string $connectionId, array $data): \Generator
    {
        if (!isset($this->connections[$connectionId])) {
            throw new \InvalidArgumentException("Connection {$connectionId} not found");
        }

        $connection = $this->connections[$connectionId];
        
        // Encode data as JSON
        $payload = json_encode($data);
        
        // Create text frame
        $frame = new WebSocketFrame(WebSocketFrame::OPCODE_TEXT, $payload);
        
        // Send frame
        yield $connection->sendFrame($frame);
        
        $this->metrics['messages_sent']++;
        $this->metrics['frames_sent']++;
        $this->metrics['bytes_sent'] += strlen($payload);
        
        $this->logger->debug('Message sent over WebSocket HTTP/3', [
            'connection_id' => $connectionId,
            'payload_size' => strlen($payload)
        ]);
    }

    /**
     * Broadcast message to multiple connections
     */
    public function broadcast(array $connectionIds, array $data): \Generator
    {
        $futures = [];
        
        foreach ($connectionIds as $connectionId) {
            if (isset($this->connections[$connectionId])) {
                $futures[] = $this->sendMessage($connectionId, $data);
            }
        }

        if (!empty($futures)) {
            yield \Amp\Future::awaitAll($futures);
        }
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
        
        $this->logger->debug('Connection joined channel', [
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
        }
        
        $this->logger->debug('Connection left channel', [
            'connection_id' => $connectionId,
            'channel' => $channel
        ]);
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
        yield $connection->close($code, $reason);
    }

    /**
     * Handle connection close
     */
    private function handleConnectionClose(WebSocketHttp3Connection $connection, int $code, string $reason): \Generator
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
        yield $this->triggerEvent('disconnect', $connection, $code, $reason);
        
        $this->logger->info('WebSocket HTTP/3 connection closed', [
            'connection_id' => $connectionId,
            'code' => $code,
            'reason' => $reason
        ]);
    }

    /**
     * Handle connection error
     */
    private function handleConnectionError(WebSocketHttp3Connection $connection, \Throwable $error): \Generator
    {
        $this->logger->error('WebSocket HTTP/3 connection error', [
            'connection_id' => $connection->getId(),
            'error' => $error->getMessage()
        ]);
        
        // Trigger error event
        yield $this->triggerEvent('error', $connection, $error);
    }

    /**
     * Start WebSocket over HTTP/3 server
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting WebSocket over HTTP/3 server');

        // Start HTTP/3 server if not already running
        if (!$this->http3Server->isRunning()) {
            yield $this->http3Server->start();
        }

        $this->isRunning = true;
        
        // Start maintenance tasks
        \Amp\async(function() {
            yield $this->startMaintenanceTasks();
        });

        $this->logger->info('WebSocket over HTTP/3 server started');
    }

    /**
     * Stop server
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping WebSocket over HTTP/3 server');

        // Close all connections
        $closeFutures = [];
        foreach ($this->connections as $connection) {
            $closeFutures[] = $this->closeConnection($connection->getId());
        }

        if (!empty($closeFutures)) {
            yield \Amp\Future::awaitAll($closeFutures);
        }

        $this->isRunning = false;
        
        $this->logger->info('WebSocket over HTTP/3 server stopped');
    }

    /**
     * Get protocol name
     */
    public function getName(): string
    {
        return 'WebSocket-HTTP3';
    }

    /**
     * Get protocol version
     */
    public function getVersion(): string
    {
        return 'RFC6455-over-HTTP3';
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
        $this->metrics['http3_streams'] = array_sum(array_map(
            fn($conn) => $conn->getStreamCount(), 
            $this->connections
        ));

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
     * Trigger event
     */
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

    /**
     * Generate connection ID
     */
    private function generateConnectionId(): string
    {
        return 'ws-h3-' . bin2hex(random_bytes(8));
    }

    /**
     * Update average frame size
     */
    private function updateAverageFrameSize(int $frameSize): void
    {
        if ($this->metrics['average_frame_size'] === 0) {
            $this->metrics['average_frame_size'] = $frameSize;
        } else {
            $alpha = 0.1;
            $this->metrics['average_frame_size'] = 
                (1 - $alpha) * $this->metrics['average_frame_size'] + $alpha * $frameSize;
        }
    }

    /**
     * Start maintenance tasks
     */
    private function startMaintenanceTasks(): \Generator
    {
        while ($this->isRunning) {
            yield \Amp\delay(30000); // 30 seconds
            
            try {
                // Cleanup dead connections
                yield $this->cleanupDeadConnections();
                
                // Send periodic pings
                yield $this->sendPeriodicPings();
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in maintenance tasks', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Cleanup dead connections
     */
    private function cleanupDeadConnections(): \Generator
    {
        $deadConnections = [];
        
        foreach ($this->connections as $connectionId => $connection) {
            if (!$connection->isAlive()) {
                $deadConnections[] = $connectionId;
            }
        }

        foreach ($deadConnections as $connectionId) {
            yield $this->closeConnection($connectionId, 1006, 'Connection timeout');
        }

        if (!empty($deadConnections)) {
            $this->logger->debug('Cleaned up dead connections', [
                'count' => count($deadConnections)
            ]);
        }
    }

    /**
     * Send periodic pings
     */
    private function sendPeriodicPings(): \Generator
    {
        $pingFrame = new WebSocketFrame(WebSocketFrame::OPCODE_PING, 'ping-' . time());
        
        foreach ($this->connections as $connection) {
            try {
                yield $connection->sendFrame($pingFrame);
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to send ping', [
                    'connection_id' => $connection->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enable_compression' => true,
            'max_frame_size' => 2097152, // 2MB
            'max_message_size' => 10485760, // 10MB
            'ping_interval' => 30,
            'connection_timeout' => 300,
            'max_connections' => 10000,
            'enable_multiplexing' => true,
            'stream_priority' => 'high',
            'enable_flow_control' => true
        ];
    }
}