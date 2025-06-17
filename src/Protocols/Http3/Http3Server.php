<?php

declare(strict_types=1);

namespace EaseAppPHP\HighPer\Realtime\Protocols\Http3;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use EaseAppPHP\HighPer\Realtime\Protocols\ProtocolInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP/3 Server Implementation
 * 
 * Implements HTTP/3 over QUIC with automatic protocol negotiation,
 * multiplexing, and 0-RTT support
 */
class Http3Server implements ProtocolInterface
{
    private LoggerInterface $logger;
    private array $config;
    private QuicTransport $quicTransport;
    private Http3RequestHandler $requestHandler;
    private Http3ResponseHandler $responseHandler;
    private ProtocolNegotiator $protocolNegotiator;
    
    private array $connections = [];
    private array $eventHandlers = [];
    private bool $isRunning = false;
    private array $metrics = [];

    public function __construct(LoggerInterface $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->initializeComponents();
        $this->initializeMetrics();
    }

    /**
     * Initialize HTTP/3 server components
     */
    private function initializeComponents(): void
    {
        $this->quicTransport = new QuicTransport($this->logger, $this->config);
        $this->requestHandler = new Http3RequestHandler($this->logger, $this->config);
        $this->responseHandler = new Http3ResponseHandler($this->logger, $this->config);
        $this->protocolNegotiator = new ProtocolNegotiator($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'connections' => 0,
            'active_connections' => 0,
            'requests_total' => 0,
            'requests_per_second' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'average_response_time' => 0,
            'http3_requests' => 0,
            'http2_fallbacks' => 0,
            'http1_fallbacks' => 0,
            'zero_rtt_requests' => 0,
            'protocol_upgrades' => 0
        ];
    }

    /**
     * Check if request supports HTTP/3
     */
    public function supports(Request $request): bool
    {
        // Check for HTTP/3 indicators
        $userAgent = $request->getHeader('user-agent') ?? '';
        $altSvc = $request->getHeader('alt-svc') ?? '';
        
        // Check if client supports HTTP/3
        return $this->protocolNegotiator->supportsHttp3($request) ||
               strpos($altSvc, 'h3=') !== false ||
               $this->hasQuicCapability($request);
    }

    /**
     * Handle HTTP/3 handshake/upgrade
     */
    public function handleHandshake(Request $request): \Generator
    {
        try {
            // Determine best protocol version
            $protocol = yield $this->protocolNegotiator->negotiate($request);
            
            switch ($protocol) {
                case 'h3':
                    return yield $this->handleHttp3Handshake($request);
                    
                case 'h2':
                    $this->metrics['http2_fallbacks']++;
                    return yield $this->handleHttp2Fallback($request);
                    
                case 'http/1.1':
                    $this->metrics['http1_fallbacks']++;
                    return yield $this->handleHttp1Fallback($request);
                    
                default:
                    throw new \InvalidArgumentException("Unsupported protocol: {$protocol}");
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('HTTP/3 handshake failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            // Fallback to HTTP/2 or HTTP/1.1
            return yield $this->handleFallback($request);
        }
    }

    /**
     * Handle HTTP/3 specific handshake
     */
    private function handleHttp3Handshake(Request $request): \Generator
    {
        // Extract connection information
        $remoteAddress = $request->getClient()->getRemoteAddress();
        
        // Create or get QUIC connection
        $connection = yield $this->quicTransport->createConnection($remoteAddress, [
            'enable_0rtt' => $this->config['enable_0rtt'],
            'enable_early_data' => true
        ]);

        // Store connection
        $connectionId = $connection->getId();
        $this->connections[$connectionId] = $connection;
        $this->metrics['connections']++;
        $this->metrics['active_connections']++;

        // Setup HTTP/3 stream
        $stream = yield $connection->createBidirectionalStream();
        
        // Create HTTP/3 response with Alt-Svc header
        $response = new Response(200, [
            'alt-svc' => 'h3=":' . $this->config['port'] . '"; ma=3600',
            'server' => 'HighPer-HTTP3/1.0',
            'connection' => 'upgrade',
            'upgrade' => 'h3'
        ]);

        $this->logger->info('HTTP/3 handshake completed', [
            'connection_id' => $connectionId,
            'remote_address' => (string)$remoteAddress,
            'user_agent' => $request->getHeader('user-agent')
        ]);

        // Register connection handlers
        yield $this->registerConnectionHandlers($connection);

        return $response;
    }

    /**
     * Register connection event handlers
     */
    private function registerConnectionHandlers(QuicConnection $connection): \Generator
    {
        // Handle incoming HTTP/3 requests
        $connection->onStreamData(function($streamId, $data) use ($connection) {
            yield $this->handleHttp3Request($connection, $streamId, $data);
        });

        // Handle connection close
        $connection->onClose(function() use ($connection) {
            $connectionId = $connection->getId();
            unset($this->connections[$connectionId]);
            $this->metrics['active_connections']--;
            
            $this->logger->debug('HTTP/3 connection closed', [
                'connection_id' => $connectionId
            ]);
        });

        // Handle connection errors
        $connection->onError(function($error) use ($connection) {
            $this->logger->error('HTTP/3 connection error', [
                'connection_id' => $connection->getId(),
                'error' => $error
            ]);
        });
    }

    /**
     * Handle HTTP/3 request over QUIC stream
     */
    private function handleHttp3Request(QuicConnection $connection, int $streamId, string $data): \Generator
    {
        $startTime = microtime(true);
        
        try {
            // Parse HTTP/3 request
            $request = yield $this->requestHandler->parseHttp3Request($data);
            
            if (!$request) {
                $this->logger->warning('Invalid HTTP/3 request', [
                    'connection_id' => $connection->getId(),
                    'stream_id' => $streamId
                ]);
                return;
            }

            $this->metrics['requests_total']++;
            $this->metrics['http3_requests']++;

            // Check for 0-RTT request
            if ($connection->is0RTTData($data)) {
                $this->metrics['zero_rtt_requests']++;
                $this->logger->debug('0-RTT request processed', [
                    'uri' => (string)$request->getUri(),
                    'method' => $request->getMethod()
                ]);
            }

            // Call registered handlers
            if (isset($this->eventHandlers['message'])) {
                foreach ($this->eventHandlers['message'] as $handler) {
                    $response = yield $handler($request, $connection);
                    
                    if ($response instanceof Response) {
                        yield $this->sendHttp3Response($connection, $streamId, $response);
                        break;
                    }
                }
            }

            // Update metrics
            $responseTime = microtime(true) - $startTime;
            $this->updateResponseTimeMetrics($responseTime);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error handling HTTP/3 request', [
                'error' => $e->getMessage(),
                'connection_id' => $connection->getId(),
                'stream_id' => $streamId
            ]);
            
            // Send error response
            $errorResponse = new Response(500, [], 'Internal Server Error');
            yield $this->sendHttp3Response($connection, $streamId, $errorResponse);
        }
    }

    /**
     * Send HTTP/3 response over QUIC stream
     */
    private function sendHttp3Response(QuicConnection $connection, int $streamId, Response $response): \Generator
    {
        try {
            // Serialize HTTP/3 response
            $responseData = yield $this->responseHandler->serializeHttp3Response($response);
            
            // Send over QUIC stream
            yield $connection->sendStreamData($streamId, $responseData, true);
            
            // Update metrics
            $this->metrics['bytes_sent'] += strlen($responseData);
            
            $this->logger->debug('HTTP/3 response sent', [
                'connection_id' => $connection->getId(),
                'stream_id' => $streamId,
                'status' => $response->getStatus(),
                'size' => strlen($responseData)
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send HTTP/3 response', [
                'error' => $e->getMessage(),
                'connection_id' => $connection->getId(),
                'stream_id' => $streamId
            ]);
        }
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
        
        // Create new stream for message
        $stream = yield $connection->createUnidirectionalStream();
        $streamId = $stream->getId();
        
        // Encode data as JSON
        $jsonData = json_encode($data);
        
        // Send data
        yield $connection->sendStreamData($streamId, $jsonData, true);
        
        $this->logger->debug('Message sent over HTTP/3', [
            'connection_id' => $connectionId,
            'stream_id' => $streamId,
            'data_size' => strlen($jsonData)
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
     * Broadcast to all connections
     */
    public function broadcastToChannel(string $channel, array $data): \Generator
    {
        // For HTTP/3, broadcast to all active connections
        // In a real implementation, you'd filter by channel membership
        $connectionIds = array_keys($this->connections);
        yield $this->broadcast($connectionIds, $data);
    }

    /**
     * Join connection to channel (HTTP/3 specific implementation)
     */
    public function joinChannel(string $connectionId, string $channel): \Generator
    {
        // Mark connection as part of channel
        if (isset($this->connections[$connectionId])) {
            $connection = $this->connections[$connectionId];
            $connection->addToChannel($channel);
            
            $this->logger->debug('Connection joined channel', [
                'connection_id' => $connectionId,
                'channel' => $channel
            ]);
        }
    }

    /**
     * Leave channel
     */
    public function leaveChannel(string $connectionId, string $channel): \Generator
    {
        if (isset($this->connections[$connectionId])) {
            $connection = $this->connections[$connectionId];
            $connection->removeFromChannel($channel);
            
            $this->logger->debug('Connection left channel', [
                'connection_id' => $connectionId,
                'channel' => $channel
            ]);
        }
    }

    /**
     * Close connection
     */
    public function closeConnection(string $connectionId, int $code = 1000, string $reason = ''): \Generator
    {
        if (isset($this->connections[$connectionId])) {
            $connection = $this->connections[$connectionId];
            yield $connection->close($code, $reason);
            
            unset($this->connections[$connectionId]);
            $this->metrics['active_connections']--;
        }
    }

    /**
     * Start HTTP/3 server
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting HTTP/3 server', [
            'port' => $this->config['port'],
            'quic_version' => $this->quicTransport->getQuicVersion()
        ]);

        // Start QUIC transport
        yield $this->quicTransport->start('0.0.0.0', $this->config['port']);
        
        $this->isRunning = true;
        
        // Start metrics collection
        \Amp\async(function() {
            yield $this->startMetricsCollection();
        });

        $this->logger->info('HTTP/3 server started successfully');
    }

    /**
     * Stop HTTP/3 server
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping HTTP/3 server');

        // Close all connections
        $closeFutures = [];
        foreach ($this->connections as $connectionId => $connection) {
            $closeFutures[] = $this->closeConnection($connectionId);
        }

        if (!empty($closeFutures)) {
            yield \Amp\Future::awaitAll($closeFutures);
        }

        // Stop QUIC transport
        yield $this->quicTransport->stop();
        
        $this->isRunning = false;
        
        $this->logger->info('HTTP/3 server stopped');
    }

    /**
     * Get protocol name
     */
    public function getName(): string
    {
        return 'HTTP/3';
    }

    /**
     * Get protocol version
     */
    public function getVersion(): string
    {
        return 'h3-34'; // HTTP/3 draft version
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
        $connections = [];
        
        foreach ($this->connections as $connectionId => $connection) {
            if ($connection->isInChannel($channel)) {
                $connections[] = $connectionId;
            }
        }

        return $connections;
    }

    /**
     * Get metrics
     */
    public function getMetrics(): array
    {
        // Update dynamic metrics
        $this->metrics['active_connections'] = count($this->connections);
        
        // Add QUIC transport metrics
        $quicStats = $this->quicTransport->getConnectionStats();
        $this->metrics = array_merge($this->metrics, [
            'quic_connections' => $quicStats['total_connections'],
            'quic_bytes_sent' => $quicStats['bytes_sent'],
            'quic_bytes_received' => $quicStats['bytes_received'],
            'quic_packets_sent' => $quicStats['packets_sent'],
            'quic_packets_received' => $quicStats['packets_received']
        ]);

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
     * Check if client has QUIC capability
     */
    private function hasQuicCapability(Request $request): bool
    {
        // Check various indicators of QUIC support
        $userAgent = strtolower($request->getHeader('user-agent') ?? '');
        
        return strpos($userAgent, 'chrome') !== false ||
               strpos($userAgent, 'firefox') !== false ||
               strpos($userAgent, 'safari') !== false ||
               $request->hasHeader('sec-ch-ua-platform');
    }

    /**
     * Update response time metrics
     */
    private function updateResponseTimeMetrics(float $responseTime): void
    {
        if ($this->metrics['average_response_time'] === 0) {
            $this->metrics['average_response_time'] = $responseTime;
        } else {
            // Exponential moving average
            $alpha = 0.1;
            $this->metrics['average_response_time'] = 
                (1 - $alpha) * $this->metrics['average_response_time'] + $alpha * $responseTime;
        }
    }

    /**
     * Start metrics collection
     */
    private function startMetricsCollection(): \Generator
    {
        $lastRequestCount = 0;
        
        while ($this->isRunning) {
            yield \Amp\delay(1000); // 1 second
            
            // Calculate requests per second
            $currentRequests = $this->metrics['requests_total'];
            $this->metrics['requests_per_second'] = $currentRequests - $lastRequestCount;
            $lastRequestCount = $currentRequests;
            
            // Cleanup expired connections
            yield $this->quicTransport->cleanupConnections();
        }
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'port' => 443,
            'enable_0rtt' => true,
            'enable_early_data' => true,
            'max_connections' => 10000,
            'connection_timeout' => 300,
            'enable_protocol_negotiation' => true,
            'fallback_protocols' => ['h2', 'http/1.1'],
            'alt_svc_max_age' => 3600,
            'quic_version' => 'draft-34'
        ];
    }
}