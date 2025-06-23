<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\Http3;

use Amp\Socket\UdpSocket;
use Amp\Socket\SocketAddress;
use Psr\Log\LoggerInterface;

/**
 * QUIC Connection Implementation
 * 
 * Represents a single QUIC connection with stream multiplexing,
 * 0-RTT resumption, and connection migration support
 */
class QuicConnection
{
    private string $connectionId;
    private SocketAddress $remoteAddress;
    private UdpSocket $socket;
    private LoggerInterface $logger;
    private array $config;
    
    private array $streams = [];
    private QuicStreamManager $streamManager;
    private QuicFlowController $flowController;
    private QuicCongestionController $congestionController;
    private QuicCryptoState $cryptoState;
    
    private int $state = self::STATE_HANDSHAKING;
    private float $lastActivity;
    private array $statistics;
    private bool $is0RTTEnabled = false;
    private array $resumptionTicket = [];

    // Connection states
    public const STATE_HANDSHAKING = 1;
    public const STATE_CONNECTED = 2;
    public const STATE_CLOSING = 3;
    public const STATE_CLOSED = 4;
    public const STATE_DRAINING = 5;

    public function __construct(
        string $connectionId,
        SocketAddress $remoteAddress,
        UdpSocket $socket,
        LoggerInterface $logger,
        array $config
    ) {
        $this->connectionId = $connectionId;
        $this->remoteAddress = $remoteAddress;
        $this->socket = $socket;
        $this->logger = $logger;
        $this->config = $config;
        $this->lastActivity = microtime(true);
        
        $this->initializeComponents();
        $this->initializeStatistics();
    }

    /**
     * Initialize connection components
     */
    private function initializeComponents(): void
    {
        $this->streamManager = new QuicStreamManager($this->logger, $this->config);
        $this->flowController = new QuicFlowController($this->config);
        $this->congestionController = new QuicCongestionController($this->config);
        $this->cryptoState = new QuicCryptoState($this->logger, $this->config);
    }

    /**
     * Initialize connection statistics
     */
    private function initializeStatistics(): void
    {
        $this->statistics = [
            'streams' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'packets_sent' => 0,
            'packets_received' => 0,
            'round_trip_time' => 0,
            'congestion_window' => $this->config['initial_congestion_window'] ?? 10,
            'retransmissions' => 0,
            'created_at' => microtime(true)
        ];
    }

    /**
     * Handle incoming QUIC packet
     */
    public function handlePacket(QuicPacket $packet): \Generator
    {
        $this->lastActivity = microtime(true);
        $this->statistics['packets_received']++;
        $this->statistics['bytes_received'] += $packet->getSize();

        try {
            // Decrypt packet if needed
            if ($packet->isEncrypted()) {
                $packet = yield $this->cryptoState->decryptPacket($packet);
            }

            // Handle based on packet type
            switch ($packet->getType()) {
                case QuicPacket::TYPE_INITIAL:
                    yield $this->handleInitialPacket($packet);
                    break;
                    
                case QuicPacket::TYPE_HANDSHAKE:
                    yield $this->handleHandshakePacket($packet);
                    break;
                    
                case QuicPacket::TYPE_SHORT:
                    yield $this->handleShortPacket($packet);
                    break;
                    
                case QuicPacket::TYPE_0RTT:
                    yield $this->handle0RTTPacket($packet);
                    break;
                    
                case QuicPacket::TYPE_RETRY:
                    yield $this->handleRetryPacket($packet);
                    break;
                    
                default:
                    $this->logger->warning('Unknown packet type', [
                        'type' => $packet->getType(),
                        'connection_id' => $this->connectionId
                    ]);
            }

        } catch (\Throwable $e) {
            $this->logger->error('Error handling packet', [
                'error' => $e->getMessage(),
                'connection_id' => $this->connectionId,
                'packet_type' => $packet->getType()
            ]);
        }
    }

    /**
     * Handle initial packet (connection establishment)
     */
    private function handleInitialPacket(QuicPacket $packet): \Generator
    {
        if ($this->state !== self::STATE_HANDSHAKING) {
            $this->logger->warning('Initial packet received in wrong state', [
                'state' => $this->state,
                'connection_id' => $this->connectionId
            ]);
            return;
        }

        // Process TLS handshake
        yield $this->cryptoState->processHandshake($packet);
        
        // Check for 0-RTT capability
        if ($packet->has0RTTToken() && $this->config['enable_0rtt']) {
            $this->is0RTTEnabled = true;
            $this->logger->debug('0-RTT enabled for connection', [
                'connection_id' => $this->connectionId
            ]);
        }

        // Send handshake response
        yield $this->sendHandshakeResponse();
    }

    /**
     * Handle handshake packet
     */
    private function handleHandshakePacket(QuicPacket $packet): \Generator
    {
        yield $this->cryptoState->processHandshake($packet);
        
        if ($this->cryptoState->isHandshakeComplete()) {
            $this->state = self::STATE_CONNECTED;
            
            $this->logger->info('QUIC handshake completed', [
                'connection_id' => $this->connectionId,
                'took_ms' => round((microtime(true) - $this->statistics['created_at']) * 1000, 2),
                '0rtt_enabled' => $this->is0RTTEnabled
            ]);

            // Generate resumption ticket for future 0-RTT
            if ($this->config['enable_0rtt']) {
                $this->resumptionTicket = yield $this->cryptoState->generateResumptionTicket();
            }
        }
    }

    /**
     * Handle short packet (application data)
     */
    private function handleShortPacket(QuicPacket $packet): \Generator
    {
        if ($this->state !== self::STATE_CONNECTED) {
            $this->logger->warning('Short packet received in wrong state', [
                'state' => $this->state,
                'connection_id' => $this->connectionId
            ]);
            return;
        }

        // Process frames within the packet
        $frames = $packet->getFrames();
        
        foreach ($frames as $frame) {
            yield $this->handleFrame($frame);
        }

        // Update congestion control
        $this->congestionController->onPacketReceived($packet);
    }

    /**
     * Handle 0-RTT packet (early data)
     */
    private function handle0RTTPacket(QuicPacket $packet): \Generator
    {
        if (!$this->is0RTTEnabled) {
            $this->logger->warning('0-RTT packet received but 0-RTT not enabled', [
                'connection_id' => $this->connectionId
            ]);
            return;
        }

        // Validate 0-RTT data
        if (yield $this->cryptoState->validate0RTTPacket($packet)) {
            // Process frames (early application data)
            $frames = $packet->getFrames();
            
            foreach ($frames as $frame) {
                yield $this->handleFrame($frame);
            }
            
            $this->logger->debug('0-RTT packet processed', [
                'connection_id' => $this->connectionId,
                'frame_count' => count($frames)
            ]);
        } else {
            $this->logger->warning('Invalid 0-RTT packet', [
                'connection_id' => $this->connectionId
            ]);
        }
    }

    /**
     * Handle individual frames
     */
    private function handleFrame(QuicFrame $frame): \Generator
    {
        switch ($frame->getType()) {
            case QuicFrame::TYPE_STREAM:
                yield $this->handleStreamFrame($frame);
                break;
                
            case QuicFrame::TYPE_ACK:
                yield $this->handleAckFrame($frame);
                break;
                
            case QuicFrame::TYPE_CONNECTION_CLOSE:
                yield $this->handleConnectionCloseFrame($frame);
                break;
                
            case QuicFrame::TYPE_MAX_DATA:
                $this->flowController->updateMaxData($frame->getMaxData());
                break;
                
            case QuicFrame::TYPE_MAX_STREAMS:
                $this->streamManager->updateMaxStreams($frame->getMaxStreams());
                break;
                
            case QuicFrame::TYPE_PING:
                yield $this->sendPong();
                break;
                
            default:
                $this->logger->debug('Unhandled frame type', [
                    'type' => $frame->getType(),
                    'connection_id' => $this->connectionId
                ]);
        }
    }

    /**
     * Handle stream frame (HTTP/3 data)
     */
    private function handleStreamFrame(QuicFrame $frame): \Generator
    {
        $streamId = $frame->getStreamId();
        
        // Get or create stream
        if (!isset($this->streams[$streamId])) {
            $this->streams[$streamId] = $this->streamManager->createStream($streamId);
            $this->statistics['streams']++;
        }

        $stream = $this->streams[$streamId];
        
        // Process stream data
        yield $stream->handleData($frame->getData(), $frame->isFinished());
        
        // Update flow control
        $this->flowController->onDataReceived(strlen($frame->getData()));
    }

    /**
     * Handle ACK frame (acknowledgment)
     */
    private function handleAckFrame(QuicFrame $frame): \Generator
    {
        $ackedPackets = $frame->getAckedPackets();
        
        foreach ($ackedPackets as $packetNumber => $timestamp) {
            // Update RTT calculation
            $rtt = microtime(true) - $timestamp;
            $this->updateRTT($rtt);
            
            // Update congestion control
            $this->congestionController->onPacketAcked($packetNumber, $rtt);
        }
    }

    /**
     * Create new bidirectional stream
     */
    public function createBidirectionalStream(): \Generator
    {
        $streamId = $this->streamManager->getNextBidirectionalStreamId();
        $stream = $this->streamManager->createStream($streamId);
        
        $this->streams[$streamId] = $stream;
        $this->statistics['streams']++;
        
        $this->logger->debug('Created bidirectional stream', [
            'stream_id' => $streamId,
            'connection_id' => $this->connectionId
        ]);

        return $stream;
    }

    /**
     * Create new unidirectional stream
     */
    public function createUnidirectionalStream(): \Generator
    {
        $streamId = $this->streamManager->getNextUnidirectionalStreamId();
        $stream = $this->streamManager->createStream($streamId);
        
        $this->streams[$streamId] = $stream;
        $this->statistics['streams']++;
        
        $this->logger->debug('Created unidirectional stream', [
            'stream_id' => $streamId,
            'connection_id' => $this->connectionId
        ]);

        return $stream;
    }

    /**
     * Send data on stream
     */
    public function sendStreamData(int $streamId, string $data, bool $finished = false): \Generator
    {
        if (!isset($this->streams[$streamId])) {
            throw new \InvalidArgumentException("Stream {$streamId} not found");
        }

        $stream = $this->streams[$streamId];
        
        // Create stream frame
        $frame = new QuicFrame(QuicFrame::TYPE_STREAM, [
            'stream_id' => $streamId,
            'data' => $data,
            'finished' => $finished
        ]);

        // Send packet with frame
        yield $this->sendFrame($frame);
        
        // Update statistics
        $this->statistics['bytes_sent'] += strlen($data);
    }

    /**
     * Send frame in packet
     */
    private function sendFrame(QuicFrame $frame): \Generator
    {
        $packet = new QuicPacket(QuicPacket::TYPE_SHORT, [
            'destination_connection_id' => $this->connectionId,
            'frames' => [$frame]
        ]);

        yield $this->sendPacket($packet);
    }

    /**
     * Send QUIC packet
     */
    private function sendPacket(QuicPacket $packet): \Generator
    {
        try {
            // Encrypt packet if connection is established
            if ($this->state === self::STATE_CONNECTED) {
                $packet = yield $this->cryptoState->encryptPacket($packet);
            }

            // Serialize packet
            $data = $packet->serialize();
            
            // Apply congestion control
            if (!$this->congestionController->canSend(strlen($data))) {
                yield $this->congestionController->waitForWindow();
            }

            // Send UDP packet
            yield $this->socket->send($data, $this->remoteAddress);
            
            // Update statistics
            $this->statistics['packets_sent']++;
            $this->statistics['bytes_sent'] += strlen($data);
            
            // Update congestion control
            $this->congestionController->onPacketSent($packet);
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send packet', [
                'error' => $e->getMessage(),
                'connection_id' => $this->connectionId
            ]);
        }
    }

    /**
     * Migrate connection to new address
     */
    public function migrateToAddress(SocketAddress $newAddress): void
    {
        $oldAddress = $this->remoteAddress;
        $this->remoteAddress = $newAddress;
        
        $this->logger->info('Connection migrated', [
            'connection_id' => $this->connectionId,
            'old_address' => (string)$oldAddress,
            'new_address' => (string)$newAddress
        ]);
    }

    /**
     * Update RTT calculation
     */
    private function updateRTT(float $rtt): void
    {
        if ($this->statistics['round_trip_time'] === 0) {
            $this->statistics['round_trip_time'] = $rtt;
        } else {
            // Exponential weighted moving average
            $alpha = 0.125;
            $this->statistics['round_trip_time'] = 
                (1 - $alpha) * $this->statistics['round_trip_time'] + $alpha * $rtt;
        }
    }

    /**
     * Close connection
     */
    public function close(int $errorCode = 0, string $reason = ''): \Generator
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }

        $this->state = self::STATE_CLOSING;
        
        // Send connection close frame
        $frame = new QuicFrame(QuicFrame::TYPE_CONNECTION_CLOSE, [
            'error_code' => $errorCode,
            'reason' => $reason
        ]);

        yield $this->sendFrame($frame);
        
        // Close all streams
        foreach ($this->streams as $stream) {
            $stream->close();
        }

        $this->state = self::STATE_CLOSED;
        
        $this->logger->debug('Connection closed', [
            'connection_id' => $this->connectionId,
            'error_code' => $errorCode,
            'reason' => $reason
        ]);
    }

    /**
     * Check if connection is active
     */
    public function isActive(): bool
    {
        return $this->state === self::STATE_CONNECTED;
    }

    /**
     * Check if connection is expired
     */
    public function isExpired(float $currentTime): bool
    {
        $idleTime = $currentTime - $this->lastActivity;
        return $idleTime > $this->config['idle_timeout'];
    }

    /**
     * Get connection ID
     */
    public function getId(): string
    {
        return $this->connectionId;
    }

    /**
     * Get remote address
     */
    public function getRemoteAddress(): SocketAddress
    {
        return $this->remoteAddress;
    }

    /**
     * Get connection statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Get 0-RTT resumption ticket
     */
    public function getResumptionTicket(): array
    {
        return $this->resumptionTicket;
    }
}