<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\Http3;

use Amp\Future;
use Amp\Socket\UdpSocket;
use Amp\Socket\SocketAddress;
use Psr\Log\LoggerInterface;

/**
 * QUIC Transport Layer Implementation
 * 
 * Implements QUIC (RFC 9000) transport protocol for HTTP/3 support
 * Features: 0-RTT resumption, connection migration, multiplexing
 */
class QuicTransport
{
    private LoggerInterface $logger;
    private array $config;
    private UdpSocket $socket;
    private array $connections = [];
    private array $connectionPools = [];
    private QuicPacketProcessor $packetProcessor;
    private QuicConnectionManager $connectionManager;
    private QuicCryptoManager $cryptoManager;
    
    public function __construct(LoggerInterface $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->packetProcessor = new QuicPacketProcessor($logger, $this->config);
        $this->connectionManager = new QuicConnectionManager($logger, $this->config);
        $this->cryptoManager = new QuicCryptoManager($logger, $this->config);
    }

    /**
     * Start QUIC transport server
     */
    public function start(string $host = '0.0.0.0', int $port = 443): \Generator
    {
        $address = new SocketAddress($host, $port);
        
        try {
            $this->socket = yield \Amp\Socket\bindUdp($address);
            
            $this->logger->info('QUIC transport started', [
                'host' => $host,
                'port' => $port,
                'version' => $this->getQuicVersion()
            ]);

            // Start packet processing loop
            yield $this->startPacketProcessing();
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to start QUIC transport', [
                'error' => $e->getMessage(),
                'host' => $host,
                'port' => $port
            ]);
            throw $e;
        }
    }

    /**
     * Create new QUIC connection
     */
    public function createConnection(SocketAddress $remoteAddress, array $options = []): \Generator
    {
        $connectionId = $this->generateConnectionId();
        
        $connection = new QuicConnection(
            $connectionId,
            $remoteAddress,
            $this->socket,
            $this->logger,
            array_merge($this->config, $options)
        );

        // Initialize TLS 1.3 handshake
        yield $this->cryptoManager->initializeHandshake($connection);
        
        // Store connection
        $this->connections[$connectionId] = $connection;
        
        $this->logger->debug('QUIC connection created', [
            'connection_id' => $connectionId,
            'remote_address' => (string)$remoteAddress
        ]);

        return $connection;
    }

    /**
     * Handle incoming QUIC packet
     */
    public function handleIncomingPacket(string $data, SocketAddress $remoteAddress): \Generator
    {
        try {
            $packet = yield $this->packetProcessor->parsePacket($data);
            
            if (!$packet) {
                $this->logger->warning('Invalid QUIC packet received', [
                    'remote_address' => (string)$remoteAddress,
                    'data_length' => strlen($data)
                ]);
                return;
            }

            $connectionId = $packet->getDestinationConnectionId();
            
            // Handle new connection
            if (!isset($this->connections[$connectionId])) {
                if ($packet->isInitial()) {
                    yield $this->handleNewConnection($packet, $remoteAddress);
                } else {
                    $this->logger->warning('Packet for unknown connection', [
                        'connection_id' => $connectionId,
                        'remote_address' => (string)$remoteAddress
                    ]);
                }
                return;
            }

            $connection = $this->connections[$connectionId];
            
            // Handle connection migration
            if ($connection->getRemoteAddress()->toString() !== $remoteAddress->toString()) {
                yield $this->handleConnectionMigration($connection, $remoteAddress, $packet);
            }

            // Process packet
            yield $connection->handlePacket($packet);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error handling QUIC packet', [
                'error' => $e->getMessage(),
                'remote_address' => (string)$remoteAddress
            ]);
        }
    }

    /**
     * Handle new incoming connection
     */
    private function handleNewConnection(QuicPacket $packet, SocketAddress $remoteAddress): \Generator
    {
        $connectionId = $packet->getSourceConnectionId();
        
        // Check connection limits
        if (count($this->connections) >= $this->config['max_connections']) {
            $this->logger->warning('Connection limit reached', [
                'current_connections' => count($this->connections),
                'max_connections' => $this->config['max_connections']
            ]);
            return;
        }

        // Create new connection
        $connection = yield $this->createConnection($remoteAddress, [
            'connection_id' => $connectionId,
            'is_server' => true
        ]);

        // Handle initial packet
        yield $connection->handlePacket($packet);

        $this->logger->info('New QUIC connection established', [
            'connection_id' => $connectionId,
            'remote_address' => (string)$remoteAddress
        ]);
    }

    /**
     * Handle connection migration (mobile networks, WiFi <-> Cellular)
     */
    private function handleConnectionMigration(QuicConnection $connection, SocketAddress $newAddress, QuicPacket $packet): \Generator
    {
        if (!$this->config['enable_connection_migration']) {
            $this->logger->warning('Connection migration disabled', [
                'connection_id' => $connection->getId()
            ]);
            return;
        }

        $oldAddress = $connection->getRemoteAddress();
        
        // Validate migration
        if (yield $this->validateConnectionMigration($connection, $newAddress, $packet)) {
            $connection->migrateToAddress($newAddress);
            
            $this->logger->info('Connection migrated successfully', [
                'connection_id' => $connection->getId(),
                'old_address' => (string)$oldAddress,
                'new_address' => (string)$newAddress
            ]);
        } else {
            $this->logger->warning('Connection migration validation failed', [
                'connection_id' => $connection->getId(),
                'attempted_address' => (string)$newAddress
            ]);
        }
    }

    /**
     * Validate connection migration
     */
    private function validateConnectionMigration(QuicConnection $connection, SocketAddress $newAddress, QuicPacket $packet): \Generator
    {
        // Implement path validation as per RFC 9000
        // This is a simplified version - production should implement full path validation
        
        try {
            // Check if the packet contains valid connection ID
            if ($packet->getDestinationConnectionId() !== $connection->getId()) {
                return false;
            }

            // Verify packet encryption
            if (!yield $this->cryptoManager->verifyPacket($connection, $packet)) {
                return false;
            }

            // Additional security checks can be added here
            return true;
            
        } catch (\Throwable $e) {
            $this->logger->error('Connection migration validation error', [
                'error' => $e->getMessage(),
                'connection_id' => $connection->getId()
            ]);
            return false;
        }
    }

    /**
     * Start packet processing loop
     */
    private function startPacketProcessing(): \Generator
    {
        while (true) {
            try {
                [$data, $remoteAddress] = yield $this->socket->receive();
                
                // Handle packet asynchronously
                \Amp\async(function() use ($data, $remoteAddress) {
                    yield $this->handleIncomingPacket($data, $remoteAddress);
                });
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in packet processing loop', [
                    'error' => $e->getMessage()
                ]);
                
                // Brief delay before retrying
                yield Future::delay(1);
            }
        }
    }

    /**
     * Generate unique connection ID
     */
    private function generateConnectionId(): string
    {
        return bin2hex(random_bytes(8)); // 64-bit connection ID
    }

    /**
     * Get QUIC version
     */
    public function getQuicVersion(): string
    {
        return $this->config['quic_version'] ?? 'draft-34';
    }

    /**
     * Get connection statistics
     */
    public function getConnectionStats(): array
    {
        $stats = [
            'total_connections' => count($this->connections),
            'active_connections' => 0,
            'total_streams' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'packets_sent' => 0,
            'packets_received' => 0
        ];

        foreach ($this->connections as $connection) {
            if ($connection->isActive()) {
                $stats['active_connections']++;
            }
            
            $connectionStats = $connection->getStatistics();
            $stats['total_streams'] += $connectionStats['streams'];
            $stats['bytes_sent'] += $connectionStats['bytes_sent'];
            $stats['bytes_received'] += $connectionStats['bytes_received'];
            $stats['packets_sent'] += $connectionStats['packets_sent'];
            $stats['packets_received'] += $connectionStats['packets_received'];
        }

        return $stats;
    }

    /**
     * Cleanup expired connections
     */
    public function cleanupConnections(): \Generator
    {
        $now = time();
        $expiredConnections = [];

        foreach ($this->connections as $connectionId => $connection) {
            if ($connection->isExpired($now)) {
                $expiredConnections[] = $connectionId;
            }
        }

        foreach ($expiredConnections as $connectionId) {
            yield $this->closeConnection($connectionId);
        }

        if (!empty($expiredConnections)) {
            $this->logger->debug('Cleaned up expired connections', [
                'count' => count($expiredConnections)
            ]);
        }
    }

    /**
     * Close QUIC connection
     */
    public function closeConnection(string $connectionId, int $errorCode = 0, string $reason = ''): \Generator
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }

        $connection = $this->connections[$connectionId];
        
        try {
            yield $connection->close($errorCode, $reason);
            unset($this->connections[$connectionId]);
            
            $this->logger->debug('QUIC connection closed', [
                'connection_id' => $connectionId,
                'error_code' => $errorCode,
                'reason' => $reason
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error closing QUIC connection', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Stop QUIC transport
     */
    public function stop(): \Generator
    {
        $this->logger->info('Stopping QUIC transport');

        // Close all connections
        $closeFutures = [];
        foreach ($this->connections as $connectionId => $connection) {
            $closeFutures[] = $this->closeConnection($connectionId);
        }

        if (!empty($closeFutures)) {
            yield Future::awaitAll($closeFutures);
        }

        // Close UDP socket
        if (isset($this->socket)) {
            $this->socket->close();
        }

        $this->logger->info('QUIC transport stopped');
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'quic_version' => 'draft-34',
            'max_connections' => 10000,
            'connection_timeout' => 300, // 5 minutes
            'idle_timeout' => 30, // 30 seconds
            'max_streams_per_connection' => 1000,
            'initial_max_data' => 1048576, // 1MB
            'initial_max_stream_data' => 65536, // 64KB
            'max_packet_size' => 1452, // Ethernet MTU - headers
            'enable_0rtt' => true,
            'enable_connection_migration' => true,
            'congestion_control' => 'bbr2',
            'packet_loss_threshold' => 3,
            'pto_multiplier' => 2,
            'max_ack_delay' => 25, // milliseconds
            'crypto' => [
                'cipher_suite' => 'TLS_AES_256_GCM_SHA384',
                'key_exchange' => 'x25519',
                'signature_algorithm' => 'ed25519'
            ]
        ];
    }
}