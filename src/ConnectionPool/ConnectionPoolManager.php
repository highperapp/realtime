<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\ConnectionPool;

use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
use HighPerApp\HighPer\Realtime\Protocols\Http3\QuicConnection;
use Psr\Log\LoggerInterface;

/**
 * Connection Pool Manager for Real-Time Protocols
 * 
 * Provides intelligent connection pooling and reuse across protocol versions:
 * - HTTP/1.1, HTTP/2, HTTP/3 connection pooling
 * - WebSocket connection reuse and multiplexing
 * - QUIC connection migration and 0-RTT resumption
 * - Cross-protocol connection upgrade/downgrade
 * - Load balancing across connection pools
 * - Connection health monitoring and auto-recovery
 * - Resource optimization and connection limits
 */
class ConnectionPoolManager
{
    private LoggerInterface $logger;
    private array $config;
    private array $pools = [];
    private array $connectionFactories = [];
    private ConnectionHealthMonitor $healthMonitor;
    private LoadBalancer $loadBalancer;
    private ConnectionMigrator $migrator;
    private PoolOptimizer $optimizer;
    
    private array $activeConnections = [];
    private array $poolMetrics = [];
    private array $reusableConnections = [];
    private bool $isRunning = false;
    private int $connectionIdCounter = 0;

    // Pool types
    private const POOL_HTTP1 = 'http1';
    private const POOL_HTTP2 = 'http2';
    private const POOL_HTTP3 = 'http3';
    private const POOL_WEBSOCKET = 'websocket';
    private const POOL_QUIC = 'quic';

    // Connection states
    private const STATE_IDLE = 'idle';
    private const STATE_ACTIVE = 'active';
    private const STATE_CLOSING = 'closing';
    private const STATE_MIGRATING = 'migrating';

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->initializeComponents();
        $this->initializePools();
        $this->initializeMetrics();
    }

    /**
     * Initialize pool management components
     */
    private function initializeComponents(): void
    {
        $this->healthMonitor = new ConnectionHealthMonitor($this->logger, $this->config);
        $this->loadBalancer = new LoadBalancer($this->logger, $this->config);
        $this->migrator = new ConnectionMigrator($this->logger, $this->config);
        $this->optimizer = new PoolOptimizer($this->logger, $this->config);
    }

    /**
     * Initialize connection pools
     */
    private function initializePools(): void
    {
        $poolTypes = [
            self::POOL_HTTP1,
            self::POOL_HTTP2, 
            self::POOL_HTTP3,
            self::POOL_WEBSOCKET,
            self::POOL_QUIC
        ];

        foreach ($poolTypes as $poolType) {
            $this->pools[$poolType] = new ConnectionPool(
                $poolType,
                $this->config['pools'][$poolType] ?? [],
                $this->logger
            );
        }
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->poolMetrics = [
            'total_connections' => 0,
            'active_connections' => 0,
            'idle_connections' => 0,
            'pool_hit_rate' => 0,
            'connection_reuse_rate' => 0,
            'migrations_successful' => 0,
            'migrations_failed' => 0,
            'protocol_upgrades' => 0,
            'protocol_downgrades' => 0,
            'pool_efficiency' => 0,
            'average_connection_lifetime' => 0,
            'pools' => []
        ];

        foreach ($this->pools as $poolType => $pool) {
            $this->poolMetrics['pools'][$poolType] = [
                'size' => 0,
                'active' => 0,
                'idle' => 0,
                'created' => 0,
                'reused' => 0,
                'expired' => 0,
                'hit_rate' => 0
            ];
        }
    }

    /**
     * Register connection factory for protocol
     */
    public function registerConnectionFactory(string $protocol, callable $factory): void
    {
        $this->connectionFactories[$protocol] = $factory;
        
        $this->logger->debug('Connection factory registered', [
            'protocol' => $protocol
        ]);
    }

    /**
     * Get or create connection for protocol
     */
    public function getConnection(string $protocol, array $connectionParams = []): \Generator
    {
        $startTime = microtime(true);
        
        try {
            // Determine optimal pool for this protocol
            $poolType = $this->determineOptimalPool($protocol, $connectionParams);
            
            // Try to reuse existing connection
            $reusedConnection = yield $this->tryReuseConnection($poolType, $connectionParams);
            
            if ($reusedConnection) {
                $this->poolMetrics['pools'][$poolType]['reused']++;
                $this->poolMetrics['pools'][$poolType]['hit_rate'] = 
                    $this->calculateHitRate($poolType);
                
                $acquisitionTime = microtime(true) - $startTime;
                
                $this->logger->debug('Connection reused from pool', [
                    'pool_type' => $poolType,
                    'connection_id' => $reusedConnection->getId(),
                    'acquisition_time_ms' => round($acquisitionTime * 1000, 2)
                ]);

                return $reusedConnection;
            }

            // Create new connection
            $newConnection = yield $this->createNewConnection($protocol, $poolType, $connectionParams);
            
            // Add to pool
            yield $this->addConnectionToPool($poolType, $newConnection);
            
            $this->poolMetrics['pools'][$poolType]['created']++;
            $this->poolMetrics['total_connections']++;
            $this->poolMetrics['active_connections']++;

            $acquisitionTime = microtime(true) - $startTime;
            
            $this->logger->info('New connection created and pooled', [
                'pool_type' => $poolType,
                'protocol' => $protocol,
                'connection_id' => $newConnection->getId(),
                'acquisition_time_ms' => round($acquisitionTime * 1000, 2)
            ]);

            return $newConnection;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to get connection from pool', [
                'protocol' => $protocol,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Return connection to pool
     */
    public function returnConnection(PooledConnection $connection): \Generator
    {
        $poolType = $connection->getPoolType();
        $connectionId = $connection->getId();

        try {
            // Check if connection is still healthy
            $isHealthy = yield $this->healthMonitor->checkConnection($connection);
            
            if (!$isHealthy) {
                yield $this->removeConnection($connection);
                return;
            }

            // Reset connection state for reuse
            yield $connection->reset();
            
            // Mark as idle and available for reuse
            $connection->setState(self::STATE_IDLE);
            $connection->setLastUsed(time());

            // Add to reusable connections
            if (!isset($this->reusableConnections[$poolType])) {
                $this->reusableConnections[$poolType] = [];
            }
            
            $this->reusableConnections[$poolType][$connectionId] = $connection;
            
            $this->poolMetrics['active_connections']--;
            $this->poolMetrics['idle_connections']++;
            $this->poolMetrics['pools'][$poolType]['active']--;
            $this->poolMetrics['pools'][$poolType]['idle']++;

            $this->logger->debug('Connection returned to pool', [
                'pool_type' => $poolType,
                'connection_id' => $connectionId
            ]);

        } catch (\Throwable $e) {
            $this->logger->warning('Failed to return connection to pool', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
            
            // Remove problematic connection
            yield $this->removeConnection($connection);
        }
    }

    /**
     * Migrate connection to different protocol
     */
    public function migrateConnection(PooledConnection $connection, string $targetProtocol): \Generator
    {
        $currentPoolType = $connection->getPoolType();
        $targetPoolType = $this->determineOptimalPool($targetProtocol, []);
        
        if ($currentPoolType === $targetPoolType) {
            return $connection; // No migration needed
        }

        try {
            $connection->setState(self::STATE_MIGRATING);
            
            // Attempt connection migration
            $migratedConnection = yield $this->migrator->migrate($connection, $targetProtocol);
            
            if ($migratedConnection) {
                // Remove from old pool
                yield $this->removeConnectionFromPool($currentPoolType, $connection);
                
                // Add to new pool
                yield $this->addConnectionToPool($targetPoolType, $migratedConnection);
                
                $this->poolMetrics['migrations_successful']++;
                
                $this->logger->info('Connection migration successful', [
                    'connection_id' => $connection->getId(),
                    'from_pool' => $currentPoolType,
                    'to_pool' => $targetPoolType,
                    'target_protocol' => $targetProtocol
                ]);

                return $migratedConnection;
            } else {
                $this->poolMetrics['migrations_failed']++;
                throw new \RuntimeException('Connection migration failed');
            }

        } catch (\Throwable $e) {
            $this->poolMetrics['migrations_failed']++;
            $connection->setState(self::STATE_ACTIVE); // Restore state
            
            $this->logger->error('Connection migration failed', [
                'connection_id' => $connection->getId(),
                'from_pool' => $currentPoolType,
                'to_pool' => $targetPoolType,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Upgrade connection to newer protocol version
     */
    public function upgradeConnection(PooledConnection $connection, string $targetProtocol): \Generator
    {
        $upgradeResult = yield $this->migrateConnection($connection, $targetProtocol);
        
        if ($upgradeResult) {
            $this->poolMetrics['protocol_upgrades']++;
        }
        
        return $upgradeResult;
    }

    /**
     * Downgrade connection to older protocol version
     */
    public function downgradeConnection(PooledConnection $connection, string $targetProtocol): \Generator
    {
        $downgradeResult = yield $this->migrateConnection($connection, $targetProtocol);
        
        if ($downgradeResult) {
            $this->poolMetrics['protocol_downgrades']++;
        }
        
        return $downgradeResult;
    }

    /**
     * Optimize connection pools
     */
    public function optimizePools(): \Generator
    {
        foreach ($this->pools as $poolType => $pool) {
            yield $this->optimizePool($poolType);
        }
        
        // Update efficiency metrics
        $this->updatePoolEfficiency();
    }

    /**
     * Get pool statistics
     */
    public function getPoolStatistics(): array
    {
        $this->updatePoolMetrics();
        
        return [
            'timestamp' => time(),
            'global_metrics' => $this->poolMetrics,
            'pool_details' => $this->getDetailedPoolStats(),
            'health_status' => $this->healthMonitor->getOverallHealth(),
            'optimization_recommendations' => $this->optimizer->getRecommendations()
        ];
    }

    /**
     * Start pool manager
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting Connection Pool Manager');

        // Start pools
        foreach ($this->pools as $pool) {
            yield $pool->start();
        }

        // Start supporting services
        yield $this->healthMonitor->start();
        yield $this->optimizer->start();

        $this->isRunning = true;

        // Start maintenance tasks
        \Amp\async(function() {
            yield $this->startMaintenanceTasks();
        });

        $this->logger->info('Connection Pool Manager started');
    }

    /**
     * Stop pool manager
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping Connection Pool Manager');

        // Close all connections gracefully
        $closeFutures = [];
        
        foreach ($this->activeConnections as $connection) {
            $closeFutures[] = $this->removeConnection($connection);
        }

        if (!empty($closeFutures)) {
            yield \Amp\Future::awaitAll($closeFutures);
        }

        $this->isRunning = false;

        $this->logger->info('Connection Pool Manager stopped');
    }

    /**
     * Try to reuse existing connection
     */
    private function tryReuseConnection(string $poolType, array $connectionParams): \Generator
    {
        if (!isset($this->reusableConnections[$poolType])) {
            return null;
        }

        $availableConnections = $this->reusableConnections[$poolType];
        
        foreach ($availableConnections as $connectionId => $connection) {
            // Check if connection is compatible with parameters
            if (yield $this->isConnectionCompatible($connection, $connectionParams)) {
                // Remove from reusable pool
                unset($this->reusableConnections[$poolType][$connectionId]);
                
                // Mark as active
                $connection->setState(self::STATE_ACTIVE);
                $connection->setLastUsed(time());
                
                $this->poolMetrics['idle_connections']--;
                $this->poolMetrics['active_connections']++;
                $this->poolMetrics['pools'][$poolType]['idle']--;
                $this->poolMetrics['pools'][$poolType]['active']++;
                
                return $connection;
            }
        }

        return null;
    }

    /**
     * Create new connection
     */
    private function createNewConnection(string $protocol, string $poolType, array $connectionParams): \Generator
    {
        if (!isset($this->connectionFactories[$protocol])) {
            throw new \RuntimeException("No connection factory registered for protocol: {$protocol}");
        }

        $factory = $this->connectionFactories[$protocol];
        $rawConnection = yield $factory($connectionParams);

        // Wrap in pooled connection
        $pooledConnection = new PooledConnection(
            $this->generateConnectionId(),
            $rawConnection,
            $protocol,
            $poolType,
            $this->logger
        );

        $pooledConnection->setState(self::STATE_ACTIVE);
        $pooledConnection->setCreatedAt(time());
        $pooledConnection->setLastUsed(time());

        $this->activeConnections[$pooledConnection->getId()] = $pooledConnection;

        return $pooledConnection;
    }

    /**
     * Determine optimal pool for protocol
     */
    private function determineOptimalPool(string $protocol, array $connectionParams): string
    {
        // Map protocols to pool types
        $protocolPoolMap = [
            'http1.1' => self::POOL_HTTP1,
            'http2' => self::POOL_HTTP2,
            'http3' => self::POOL_HTTP3,
            'websocket-http1' => self::POOL_WEBSOCKET,
            'websocket-http2' => self::POOL_WEBSOCKET,
            'websocket-http3' => self::POOL_WEBSOCKET,
            'webtransport' => self::POOL_QUIC,
            'quic' => self::POOL_QUIC
        ];

        return $protocolPoolMap[$protocol] ?? self::POOL_HTTP2;
    }

    /**
     * Add connection to pool
     */
    private function addConnectionToPool(string $poolType, PooledConnection $connection): \Generator
    {
        $pool = $this->pools[$poolType];
        yield $pool->addConnection($connection);
        
        $this->poolMetrics['pools'][$poolType]['size']++;
        $this->poolMetrics['pools'][$poolType]['active']++;
    }

    /**
     * Remove connection from pool
     */
    private function removeConnectionFromPool(string $poolType, PooledConnection $connection): \Generator
    {
        $pool = $this->pools[$poolType];
        yield $pool->removeConnection($connection);
        
        $this->poolMetrics['pools'][$poolType]['size']--;
        
        if ($connection->getState() === self::STATE_ACTIVE) {
            $this->poolMetrics['pools'][$poolType]['active']--;
        } else {
            $this->poolMetrics['pools'][$poolType]['idle']--;
        }
    }

    /**
     * Remove connection completely
     */
    private function removeConnection(PooledConnection $connection): \Generator
    {
        $connectionId = $connection->getId();
        $poolType = $connection->getPoolType();
        
        // Remove from active connections
        unset($this->activeConnections[$connectionId]);
        
        // Remove from reusable connections if present
        if (isset($this->reusableConnections[$poolType][$connectionId])) {
            unset($this->reusableConnections[$poolType][$connectionId]);
        }
        
        // Close the underlying connection
        yield $connection->close();
        
        // Update metrics
        $this->poolMetrics['total_connections']--;
        
        if ($connection->getState() === self::STATE_ACTIVE) {
            $this->poolMetrics['active_connections']--;
        } else {
            $this->poolMetrics['idle_connections']--;
        }
        
        $this->poolMetrics['pools'][$poolType]['expired']++;
    }

    /**
     * Start maintenance tasks
     */
    private function startMaintenanceTasks(): \Generator
    {
        while ($this->isRunning) {
            yield \Amp\delay($this->config['maintenance_interval']);
            
            try {
                // Clean up expired connections
                yield $this->cleanupExpiredConnections();
                
                // Optimize pool sizes
                yield $this->optimizePools();
                
                // Update metrics
                $this->updatePoolMetrics();
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in pool maintenance', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Generate unique connection ID
     */
    private function generateConnectionId(): string
    {
        return 'pool-conn-' . (++$this->connectionIdCounter);
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'maintenance_interval' => 30000, // 30 seconds
            'connection_timeout' => 300, // 5 minutes
            'max_idle_time' => 60, // 1 minute
            'health_check_interval' => 30, // 30 seconds
            'pools' => [
                self::POOL_HTTP1 => [
                    'max_connections' => 50,
                    'min_connections' => 5,
                    'max_idle_time' => 60
                ],
                self::POOL_HTTP2 => [
                    'max_connections' => 100,
                    'min_connections' => 10,
                    'max_idle_time' => 120
                ],
                self::POOL_HTTP3 => [
                    'max_connections' => 200,
                    'min_connections' => 20,
                    'max_idle_time' => 300
                ],
                self::POOL_WEBSOCKET => [
                    'max_connections' => 1000,
                    'min_connections' => 50,
                    'max_idle_time' => 600
                ],
                self::POOL_QUIC => [
                    'max_connections' => 500,
                    'min_connections' => 25,
                    'max_idle_time' => 300
                ]
            ]
        ];
    }

    // Placeholder methods for full implementation
    private function isConnectionCompatible(PooledConnection $connection, array $params): \Generator { return yield true; }
    private function calculateHitRate(string $poolType): float { return 0.85; }
    private function optimizePool(string $poolType): \Generator { return yield; }
    private function updatePoolEfficiency(): void {}
    private function updatePoolMetrics(): void {}
    private function getDetailedPoolStats(): array { return []; }
    private function cleanupExpiredConnections(): \Generator { return yield; }
}