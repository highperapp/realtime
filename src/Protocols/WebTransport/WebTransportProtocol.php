<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\WebTransport;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
use HighPerApp\HighPer\Realtime\Protocols\Http3\QuicConnection;
use HighPerApp\HighPer\Realtime\Protocols\Http3\Http3Server;
use Psr\Log\LoggerInterface;

/**
 * WebTransport Protocol Implementation
 * 
 * Modern alternative to WebSocket using HTTP/3 and QUIC
 * Supports both reliable streams and unreliable datagrams
 */
class WebTransportProtocol implements ProtocolInterface
{
    private LoggerInterface $logger;
    private array $config;
    private Http3Server $http3Server;
    private WebTransportSessionManager $sessionManager;
    private WebTransportStreamManager $streamManager;
    private WebTransportDatagramManager $datagramManager;
    
    private array $sessions = [];
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
     * Initialize WebTransport components
     */
    private function initializeComponents(): void
    {
        $this->sessionManager = new WebTransportSessionManager($this->logger, $this->config);
        $this->streamManager = new WebTransportStreamManager($this->logger, $this->config);
        $this->datagramManager = new WebTransportDatagramManager($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'sessions' => 0,
            'active_sessions' => 0,
            'reliable_streams' => 0,
            'unreliable_streams' => 0,
            'datagrams_sent' => 0,
            'datagrams_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'bidirectional_streams' => 0,
            'unidirectional_streams' => 0,
            'stream_errors' => 0,
            'datagram_errors' => 0,
            'average_latency' => 0,
            'packet_loss_rate' => 0
        ];
    }

    /**
     * Check if request supports WebTransport
     */
    public function supports(Request $request): bool
    {
        $method = $request->getMethod();
        $protocolHeader = $request->getHeader('sec-webtransport-http3-draft') ?? '';
        $upgradeHeader = $request->getHeader('upgrade') ?? '';
        
        // Check for WebTransport-specific headers
        $hasWebTransportHeaders = $method === 'CONNECT' &&
                                 !empty($protocolHeader) &&
                                 strpos($upgradeHeader, 'webtransport') !== false;

        // Check if client supports HTTP/3
        $supportsHttp3 = $this->http3Server->supports($request);

        return $hasWebTransportHeaders && $supportsHttp3;
    }

    /**
     * Handle WebTransport session establishment
     */
    public function handleHandshake(Request $request): \Generator
    {
        try {
            // Validate WebTransport headers
            $protocolDraft = $request->getHeader('sec-webtransport-http3-draft');
            $origin = $request->getHeader('origin');
            
            if (empty($protocolDraft)) {
                return new Response(400, [], 'Missing WebTransport protocol header');
            }

            // Check origin if CORS is enabled
            if ($this->config['cors']['enabled'] && !$this->isOriginAllowed($origin)) {
                return new Response(403, [], 'Origin not allowed');
            }

            // Get or create QUIC connection
            $quicConnection = yield $this->getOrCreateQuicConnection($request);
            
            // Create WebTransport session
            $session = new WebTransportSession(
                $this->generateSessionId(),
                $quicConnection,
                $request,
                $this->logger,
                $this->config
            );

            // Store session
            $sessionId = $session->getId();
            $this->sessions[$sessionId] = $session;
            $this->metrics['sessions']++;
            $this->metrics['active_sessions']++;

            // Setup session handlers
            yield $this->setupSessionHandlers($session);

            // Send WebTransport response
            $response = new Response(200, [
                'sec-webtransport-http3-draft' => $protocolDraft,
                'access-control-allow-origin' => $origin ?? '*',
                'x-transport' => 'webtransport-http3',
                'cache-control' => 'no-store'
            ]);

            $this->logger->info('WebTransport session established', [
                'session_id' => $sessionId,
                'origin' => $origin,
                'protocol_draft' => $protocolDraft
            ]);

            // Trigger connect event
            yield $this->triggerEvent('connect', $session);

            return $response;
            
        } catch (\Throwable $e) {
            $this->logger->error('WebTransport handshake failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            return new Response(500, [], 'WebTransport handshake failed');
        }
    }

    /**
     * Get or create QUIC connection for WebTransport
     */
    private function getOrCreateQuicConnection(Request $request): \Generator
    {
        $remoteAddress = $request->getClient()->getRemoteAddress();
        
        // Try to reuse existing HTTP/3 connection
        $existingConnection = $this->http3Server->getConnectionByAddress($remoteAddress);
        
        if ($existingConnection && $existingConnection->isActive()) {
            return $existingConnection;
        }

        // Create new HTTP/3 connection with WebTransport support
        return yield $this->http3Server->createConnection($remoteAddress, [
            'enable_webtransport' => true,
            'enable_datagrams' => $this->config['enable_datagrams'],
            'max_datagram_size' => $this->config['max_datagram_size']
        ]);
    }

    /**
     * Setup WebTransport session event handlers
     */
    private function setupSessionHandlers(WebTransportSession $session): \Generator
    {
        // Handle incoming streams
        $session->onIncomingStream(function($stream) use ($session) {
            yield $this->handleIncomingStream($session, $stream);
        });

        // Handle incoming datagrams
        $session->onIncomingDatagram(function($datagram) use ($session) {
            yield $this->handleIncomingDatagram($session, $datagram);
        });

        // Handle session close
        $session->onClose(function($code, $reason) use ($session) {
            yield $this->handleSessionClose($session, $code, $reason);
        });

        // Handle session errors
        $session->onError(function($error) use ($session) {
            yield $this->handleSessionError($session, $error);
        });

        // Start session processing
        yield $session->start();
    }

    /**
     * Handle incoming stream
     */
    private function handleIncomingStream(WebTransportSession $session, WebTransportStream $stream): \Generator
    {
        $streamId = $stream->getId();
        $isReliable = $stream->isReliable();
        $isBidirectional = $stream->isBidirectional();
        
        // Update metrics
        if ($isReliable) {
            $this->metrics['reliable_streams']++;
        } else {
            $this->metrics['unreliable_streams']++;
        }
        
        if ($isBidirectional) {
            $this->metrics['bidirectional_streams']++;
        } else {
            $this->metrics['unidirectional_streams']++;
        }

        $this->logger->debug('Incoming WebTransport stream', [
            'session_id' => $session->getId(),
            'stream_id' => $streamId,
            'reliable' => $isReliable,
            'bidirectional' => $isBidirectional
        ]);

        // Setup stream data handler
        $stream->onData(function($data) use ($session, $stream) {
            yield $this->handleStreamData($session, $stream, $data);
        });

        // Setup stream close handler
        $stream->onClose(function() use ($session, $stream) {
            $this->logger->debug('WebTransport stream closed', [
                'session_id' => $session->getId(),
                'stream_id' => $stream->getId()
            ]);
        });

        // Trigger stream event
        yield $this->triggerEvent('stream', $session, $stream);
    }

    /**
     * Handle stream data
     */
    private function handleStreamData(WebTransportSession $session, WebTransportStream $stream, string $data): \Generator
    {
        $this->metrics['bytes_received'] += strlen($data);
        
        try {
            // Parse data (could be JSON, binary, etc.)
            $parsedData = $this->parseStreamData($data, $stream->getType());
            
            // Trigger data event
            yield $this->triggerEvent('data', $session, $stream, $parsedData);
            
        } catch (\Throwable $e) {
            $this->metrics['stream_errors']++;
            $this->logger->error('Error handling stream data', [
                'session_id' => $session->getId(),
                'stream_id' => $stream->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle incoming datagram
     */
    private function handleIncomingDatagram(WebTransportSession $session, WebTransportDatagram $datagram): \Generator
    {
        $this->metrics['datagrams_received']++;
        $this->metrics['bytes_received'] += $datagram->getSize();
        
        try {
            $data = $datagram->getData();
            $parsedData = $this->parseDatagramData($data);
            
            // Trigger datagram event
            yield $this->triggerEvent('datagram', $session, $datagram, $parsedData);
            
        } catch (\Throwable $e) {
            $this->metrics['datagram_errors']++;
            $this->logger->error('Error handling datagram', [
                'session_id' => $session->getId(),
                'datagram_id' => $datagram->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send message using reliable stream
     */
    public function sendMessage(string $sessionId, array $data): \Generator
    {
        if (!isset($this->sessions[$sessionId])) {
            throw new \InvalidArgumentException("Session {$sessionId} not found");
        }

        $session = $this->sessions[$sessionId];
        
        // Create reliable bidirectional stream
        $stream = yield $session->createBidirectionalStream(true); // reliable = true
        
        // Encode data
        $encodedData = json_encode($data);
        
        // Send data
        yield $stream->write($encodedData);
        yield $stream->close();
        
        $this->metrics['bytes_sent'] += strlen($encodedData);
        
        $this->logger->debug('Message sent via WebTransport reliable stream', [
            'session_id' => $sessionId,
            'stream_id' => $stream->getId(),
            'data_size' => strlen($encodedData)
        ]);
    }

    /**
     * Send fast message using datagram
     */
    public function sendFastMessage(string $sessionId, array $data): \Generator
    {
        if (!isset($this->sessions[$sessionId])) {
            throw new \InvalidArgumentException("Session {$sessionId} not found");
        }

        $session = $this->sessions[$sessionId];
        
        // Encode data
        $encodedData = json_encode($data);
        
        // Check size limit
        if (strlen($encodedData) > $this->config['max_datagram_size']) {
            throw new \InvalidArgumentException('Datagram too large');
        }
        
        // Create and send datagram
        $datagram = new WebTransportDatagram($encodedData);
        yield $session->sendDatagram($datagram);
        
        $this->metrics['datagrams_sent']++;
        $this->metrics['bytes_sent'] += strlen($encodedData);
        
        $this->logger->debug('Fast message sent via WebTransport datagram', [
            'session_id' => $sessionId,
            'data_size' => strlen($encodedData)
        ]);
    }

    /**
     * Send real-time data (adaptive: stream or datagram based on size/priority)
     */
    public function sendRealtimeData(string $sessionId, array $data, string $priority = 'normal'): \Generator
    {
        $encodedData = json_encode($data);
        $dataSize = strlen($encodedData);
        
        // Decide transport method based on size and priority
        if ($dataSize <= $this->config['datagram_threshold'] && 
            in_array($priority, ['urgent', 'realtime'])) {
            // Use datagram for small, urgent data
            yield $this->sendFastMessage($sessionId, $data);
        } else {
            // Use reliable stream for larger or normal priority data
            yield $this->sendMessage($sessionId, $data);
        }
    }

    /**
     * Broadcast to multiple sessions
     */
    public function broadcast(array $sessionIds, array $data): \Generator
    {
        $futures = [];
        
        foreach ($sessionIds as $sessionId) {
            if (isset($this->sessions[$sessionId])) {
                $futures[] = $this->sendMessage($sessionId, $data);
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
        $sessionIds = $this->getChannelConnections($channel);
        yield $this->broadcast($sessionIds, $data);
    }

    /**
     * Join session to channel
     */
    public function joinChannel(string $sessionId, string $channel): \Generator
    {
        if (!isset($this->sessions[$sessionId])) {
            throw new \InvalidArgumentException("Session {$sessionId} not found");
        }

        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }

        $this->channels[$channel][$sessionId] = true;
        $this->sessions[$sessionId]->addChannel($channel);
        
        $this->logger->debug('Session joined channel', [
            'session_id' => $sessionId,
            'channel' => $channel,
            'channel_size' => count($this->channels[$channel])
        ]);
    }

    /**
     * Leave channel
     */
    public function leaveChannel(string $sessionId, string $channel): \Generator
    {
        if (isset($this->channels[$channel][$sessionId])) {
            unset($this->channels[$channel][$sessionId]);
            
            if (empty($this->channels[$channel])) {
                unset($this->channels[$channel]);
            }
        }

        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId]->removeChannel($channel);
        }
        
        $this->logger->debug('Session left channel', [
            'session_id' => $sessionId,
            'channel' => $channel
        ]);
    }

    /**
     * Close session
     */
    public function closeConnection(string $sessionId, int $code = 0, string $reason = ''): \Generator
    {
        if (!isset($this->sessions[$sessionId])) {
            return;
        }

        $session = $this->sessions[$sessionId];
        yield $session->close($code, $reason);
    }

    /**
     * Handle session close
     */
    private function handleSessionClose(WebTransportSession $session, int $code, string $reason): \Generator
    {
        $sessionId = $session->getId();
        
        // Remove from channels
        foreach ($session->getChannels() as $channel) {
            yield $this->leaveChannel($sessionId, $channel);
        }

        // Remove session
        unset($this->sessions[$sessionId]);
        $this->metrics['active_sessions']--;
        
        // Trigger disconnect event
        yield $this->triggerEvent('disconnect', $session, $code, $reason);
        
        $this->logger->info('WebTransport session closed', [
            'session_id' => $sessionId,
            'code' => $code,
            'reason' => $reason
        ]);
    }

    /**
     * Handle session error
     */
    private function handleSessionError(WebTransportSession $session, \Throwable $error): \Generator
    {
        $this->logger->error('WebTransport session error', [
            'session_id' => $session->getId(),
            'error' => $error->getMessage()
        ]);
        
        // Trigger error event
        yield $this->triggerEvent('error', $session, $error);
    }

    /**
     * Start WebTransport server
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting WebTransport server');

        // Start HTTP/3 server if not already running
        if (!$this->http3Server->isRunning()) {
            yield $this->http3Server->start();
        }

        $this->isRunning = true;
        
        // Start monitoring and maintenance
        \Amp\async(function() {
            yield $this->startMonitoring();
        });

        $this->logger->info('WebTransport server started');
    }

    /**
     * Stop server
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping WebTransport server');

        // Close all sessions
        $closeFutures = [];
        foreach ($this->sessions as $session) {
            $closeFutures[] = $this->closeConnection($session->getId());
        }

        if (!empty($closeFutures)) {
            yield \Amp\Future::awaitAll($closeFutures);
        }

        $this->isRunning = false;
        
        $this->logger->info('WebTransport server stopped');
    }

    /**
     * Get protocol name
     */
    public function getName(): string
    {
        return 'WebTransport';
    }

    /**
     * Get protocol version
     */
    public function getVersion(): string
    {
        return 'draft-ietf-webtrans-http3-02';
    }

    /**
     * Get connection count (sessions)
     */
    public function getConnectionCount(): int
    {
        return $this->metrics['active_sessions'];
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
        $this->metrics['active_sessions'] = count($this->sessions);
        
        // Calculate packet loss rate
        $totalPackets = $this->metrics['datagrams_sent'] + $this->metrics['datagrams_received'];
        if ($totalPackets > 0) {
            $this->metrics['packet_loss_rate'] = $this->metrics['datagram_errors'] / $totalPackets;
        }

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
        $this->eventHandlers['data'][] = $handler;
    }

    public function onError(callable $handler): void
    {
        $this->eventHandlers['error'][] = $handler;
    }

    /**
     * Additional WebTransport-specific event handlers
     */
    public function onStream(callable $handler): void
    {
        $this->eventHandlers['stream'][] = $handler;
    }

    public function onDatagram(callable $handler): void
    {
        $this->eventHandlers['datagram'][] = $handler;
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
     * Parse stream data
     */
    private function parseStreamData(string $data, string $streamType): array
    {
        // Try JSON first
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        
        // Return raw data if not JSON
        return ['raw' => $data, 'type' => $streamType];
    }

    /**
     * Parse datagram data
     */
    private function parseDatagramData(string $data): array
    {
        // Try JSON first
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        
        // Return raw data if not JSON
        return ['raw' => $data, 'type' => 'datagram'];
    }

    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed(?string $origin): bool
    {
        if (!$origin) {
            return false;
        }
        
        $allowedOrigins = $this->config['cors']['allowed_origins'] ?? ['*'];
        
        return in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins);
    }

    /**
     * Generate session ID
     */
    private function generateSessionId(): string
    {
        return 'wt-' . bin2hex(random_bytes(16));
    }

    /**
     * Start monitoring and maintenance
     */
    private function startMonitoring(): \Generator
    {
        while ($this->isRunning) {
            yield \Amp\delay(10000); // 10 seconds
            
            try {
                // Update latency metrics
                yield $this->updateLatencyMetrics();
                
                // Cleanup dead sessions
                yield $this->cleanupDeadSessions();
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in monitoring', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Update latency metrics
     */
    private function updateLatencyMetrics(): \Generator
    {
        $latencies = [];
        
        foreach ($this->sessions as $session) {
            $latency = $session->getLatency();
            if ($latency > 0) {
                $latencies[] = $latency;
            }
        }
        
        if (!empty($latencies)) {
            $this->metrics['average_latency'] = array_sum($latencies) / count($latencies);
        }
    }

    /**
     * Cleanup dead sessions
     */
    private function cleanupDeadSessions(): \Generator
    {
        $deadSessions = [];
        
        foreach ($this->sessions as $sessionId => $session) {
            if (!$session->isActive()) {
                $deadSessions[] = $sessionId;
            }
        }

        foreach ($deadSessions as $sessionId) {
            yield $this->closeConnection($sessionId, 1006, 'Session timeout');
        }

        if (!empty($deadSessions)) {
            $this->logger->debug('Cleaned up dead sessions', [
                'count' => count($deadSessions)
            ]);
        }
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enable_datagrams' => true,
            'max_datagram_size' => 1200,
            'datagram_threshold' => 512, // Use datagrams for data smaller than this
            'max_sessions' => 10000,
            'session_timeout' => 300,
            'max_streams_per_session' => 1000,
            'enable_flow_control' => true,
            'cors' => [
                'enabled' => true,
                'allowed_origins' => ['*']
            ],
            'reliability_modes' => [
                'reliable_ordered',
                'reliable_unordered', 
                'unreliable_ordered',
                'unreliable_unordered'
            ],
            'default_reliability' => 'reliable_ordered'
        ];
    }
}