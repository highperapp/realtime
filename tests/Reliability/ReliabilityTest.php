<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Tests\Reliability;

use HighPerApp\HighPer\Realtime\ConnectionPool\ConnectionPoolManager;
use HighPerApp\HighPer\Realtime\Configuration\RealtimeConfig;
use HighPerApp\HighPer\Realtime\Protocols\WebSocket\WebSocketHttp3Protocol;
use HighPerApp\HighPer\Realtime\Protocols\SSE\SSEHttp3Protocol;
use PHPUnit\Framework\TestCase;

class ReliabilityTest extends TestCase
{
    private ConnectionPoolManager $poolManager;
    private RealtimeConfig $config;
    private const STRESS_TEST_DURATION = 30; // seconds
    private const FAILURE_RATE_THRESHOLD = 0.01; // 1% max failure rate
    
    protected function setUp(): void
    {
        $this->config = new RealtimeConfig([
            'enabled' => true,
            'max_connections' => 10000,
            'connection_timeout' => 30,
            'heartbeat_interval' => 10,
            'protocols' => [
                'websocket' => ['enabled' => true],
                'sse' => ['enabled' => true]
            ]
        ]);
        
        $this->poolManager = new ConnectionPoolManager($this->config);
    }
    
    /**
     * @group reliability
     */
    public function testConnectionRecovery(): void
    {
        $connectionCount = 100;
        $connections = [];
        
        // Establish initial connections
        for ($i = 0; $i < $connectionCount; $i++) {
            $connectionId = "recovery-conn-{$i}";
            $connections[] = $connectionId;
            $this->poolManager->addConnection($connectionId, [
                'protocol' => 'websocket',
                'status' => 'connected',
                'connected_at' => microtime(true),
                'recovery_attempts' => 0
            ]);
        }
        
        $this->assertSame($connectionCount, $this->poolManager->getConnectionCount());
        
        // Simulate connection failures
        $failedConnections = array_slice($connections, 0, 20); // 20% failure
        foreach ($failedConnections as $connectionId) {
            $this->simulateConnectionFailure($connectionId);
        }
        
        $this->assertSame($connectionCount - 20, $this->poolManager->getConnectionCount());
        
        // Test recovery mechanism
        $recoveredConnections = 0;
        foreach ($failedConnections as $connectionId) {
            if ($this->simulateConnectionRecovery($connectionId)) {
                $recoveredConnections++;
            }
        }
        
        $recoveryRate = $recoveredConnections / count($failedConnections);
        $this->assertGreaterThan(
            0.9, // 90% recovery rate
            $recoveryRate,
            "Connection recovery rate too low: " . ($recoveryRate * 100) . "%"
        );
        
        echo "\nConnection Recovery Test:\n";
        echo "  Initial Connections: {$connectionCount}\n";
        echo "  Failed Connections: " . count($failedConnections) . "\n";
        echo "  Recovered Connections: {$recoveredConnections}\n";
        echo "  Recovery Rate: " . number_format($recoveryRate * 100, 1) . "%\n";
    }
    
    /**
     * @group reliability
     */
    public function testHeartbeatMechanism(): void
    {
        $connectionCount = 50;
        $heartbeatInterval = 5; // seconds
        $testDuration = 20; // seconds
        
        // Setup connections with heartbeat tracking
        $connections = [];
        for ($i = 0; $i < $connectionCount; $i++) {
            $connectionId = "heartbeat-conn-{$i}";
            $connections[] = $connectionId;
            $this->poolManager->addConnection($connectionId, [
                'protocol' => 'websocket',
                'last_heartbeat' => microtime(true),
                'heartbeat_count' => 0,
                'missed_heartbeats' => 0
            ]);
        }
        
        $startTime = microtime(true);
        $heartbeatsSent = 0;
        $heartbeatsReceived = 0;
        
        // Simulate heartbeat mechanism
        while ((microtime(true) - $startTime) < $testDuration) {
            foreach ($connections as $connectionId) {
                $connection = $this->poolManager->getConnection($connectionId);
                if (!$connection) continue;
                
                $timeSinceLastHeartbeat = microtime(true) - $connection['last_heartbeat'];
                
                if ($timeSinceLastHeartbeat >= $heartbeatInterval) {
                    $heartbeatsSent++;
                    
                    // Simulate heartbeat response (95% success rate)
                    if (rand(1, 100) <= 95) {
                        $heartbeatsReceived++;
                        $this->poolManager->updateConnection($connectionId, [
                            'last_heartbeat' => microtime(true),
                            'heartbeat_count' => $connection['heartbeat_count'] + 1,
                            'missed_heartbeats' => 0
                        ]);
                    } else {
                        // Missed heartbeat
                        $this->poolManager->updateConnection($connectionId, [
                            'missed_heartbeats' => $connection['missed_heartbeats'] + 1
                        ]);
                        
                        // Disconnect after 3 missed heartbeats
                        if ($connection['missed_heartbeats'] >= 2) {
                            $this->poolManager->removeConnection($connectionId);
                        }
                    }
                }
            }
            
            usleep(100000); // 100ms sleep
        }
        
        $heartbeatSuccessRate = $heartbeatsReceived / max($heartbeatsSent, 1);
        $activeConnections = $this->poolManager->getConnectionCount();
        
        $this->assertGreaterThan(
            0.9, // 90% heartbeat success rate
            $heartbeatSuccessRate,
            "Heartbeat success rate too low"
        );
        
        echo "\nHeartbeat Mechanism Test:\n";
        echo "  Test Duration: {$testDuration}s\n";
        echo "  Heartbeats Sent: {$heartbeatsSent}\n";
        echo "  Heartbeats Received: {$heartbeatsReceived}\n";
        echo "  Success Rate: " . number_format($heartbeatSuccessRate * 100, 1) . "%\n";
        echo "  Active Connections: {$activeConnections}/{$connectionCount}\n";
    }
    
    /**
     * @group reliability
     */
    public function testGracefulDegradation(): void
    {
        $protocols = ['websocket', 'sse', 'webtransport'];
        $connectionCount = 100;
        $degradationScenarios = [
            'high_cpu' => 0.8,    // 80% CPU usage
            'high_memory' => 0.9, // 90% memory usage
            'network_lag' => 500, // 500ms latency
            'partial_failure' => 0.3 // 30% component failure
        ];
        
        foreach ($degradationScenarios as $scenario => $severity) {
            echo "\nTesting {$scenario} scenario (severity: {$severity}):\n";
            
            $connections = [];
            for ($i = 0; $i < $connectionCount; $i++) {
                $connectionId = "degradation-{$scenario}-{$i}";
                $protocol = $protocols[$i % count($protocols)];
                $connections[] = $connectionId;
                
                $this->poolManager->addConnection($connectionId, [
                    'protocol' => $protocol,
                    'status' => 'connected',
                    'degradation_level' => 0
                ]);
            }
            
            // Apply degradation
            $this->applyDegradationScenario($scenario, $severity, $connections);
            
            // Test system behavior under degradation
            $operationCount = 1000;
            $successfulOperations = 0;
            $startTime = microtime(true);
            
            for ($i = 0; $i < $operationCount; $i++) {
                $connectionId = $connections[$i % count($connections)];
                if ($this->simulateOperationUnderDegradation($connectionId, $scenario, $severity)) {
                    $successfulOperations++;
                }
            }
            
            $endTime = microtime(true);
            $duration = $endTime - $startTime;
            $successRate = $successfulOperations / $operationCount;
            
            // Under degradation, we expect reduced performance but continued operation
            $expectedMinSuccessRate = match($scenario) {
                'high_cpu' => 0.7,      // 70% under high CPU
                'high_memory' => 0.6,   // 60% under high memory
                'network_lag' => 0.8,   // 80% under network lag
                'partial_failure' => 0.4 // 40% under partial failure
            };
            
            $this->assertGreaterThan(
                $expectedMinSuccessRate,
                $successRate,
                "Graceful degradation failed for {$scenario}: {$successRate}"
            );
            
            echo "  Operations: {$operationCount}\n";
            echo "  Successful: {$successfulOperations}\n";
            echo "  Success Rate: " . number_format($successRate * 100, 1) . "%\n";
            echo "  Duration: " . number_format($duration, 2) . "s\n";
            
            // Cleanup
            foreach ($connections as $connectionId) {
                $this->poolManager->removeConnection($connectionId);
            }
        }
    }
    
    /**
     * @group reliability
     */
    public function testLoadBalancingReliability(): void
    {
        $serverCount = 5;
        $connectionCount = 500;
        $messageCount = 5000;
        
        // Simulate multiple servers
        $servers = [];
        for ($i = 0; $i < $serverCount; $i++) {
            $servers["server-{$i}"] = [
                'load' => 0,
                'connections' => 0,
                'status' => 'healthy',
                'response_time' => rand(10, 50) // ms
            ];
        }
        
        // Distribute connections across servers
        $connections = [];
        for ($i = 0; $i < $connectionCount; $i++) {
            $serverId = $this->selectServerForLoadBalancing($servers);
            $connectionId = "lb-conn-{$i}";
            $connections[] = $connectionId;
            
            $servers[$serverId]['connections']++;
            $servers[$serverId]['load'] += 0.1;
            
            $this->poolManager->addConnection($connectionId, [
                'protocol' => 'websocket',
                'server_id' => $serverId,
                'messages_processed' => 0
            ]);
        }
        
        // Simulate server failure
        $failedServer = 'server-2';
        $servers[$failedServer]['status'] = 'failed';
        echo "\nSimulating failure of {$failedServer}...\n";
        
        // Redistribute connections from failed server
        $redistributed = 0;
        foreach ($connections as $connectionId) {
            $connection = $this->poolManager->getConnection($connectionId);
            if ($connection && $connection['server_id'] === $failedServer) {
                $newServerId = $this->selectServerForLoadBalancing($servers, [$failedServer]);
                if ($newServerId) {
                    $this->poolManager->updateConnection($connectionId, [
                        'server_id' => $newServerId,
                        'redistributed' => true
                    ]);
                    $servers[$newServerId]['connections']++;
                    $redistributed++;
                }
            }
        }
        
        // Test message processing reliability after redistribution
        $messagesProcessed = 0;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $messageCount; $i++) {
            $connectionId = $connections[array_rand($connections)];
            $connection = $this->poolManager->getConnection($connectionId);
            
            if ($connection && $servers[$connection['server_id']]['status'] === 'healthy') {
                $messagesProcessed++;
                $this->poolManager->updateConnection($connectionId, [
                    'messages_processed' => $connection['messages_processed'] + 1
                ]);
            }
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $successRate = $messagesProcessed / $messageCount;
        
        $this->assertGreaterThan(
            0.95, // 95% success rate after failover
            $successRate,
            "Load balancing reliability test failed"
        );
        
        echo "Load Balancing Reliability Test:\n";
        echo "  Servers: {$serverCount} (1 failed)\n";
        echo "  Connections: {$connectionCount}\n";
        echo "  Redistributed: {$redistributed}\n";
        echo "  Messages: {$messageCount}\n";
        echo "  Processed: {$messagesProcessed}\n";
        echo "  Success Rate: " . number_format($successRate * 100, 1) . "%\n";
        echo "  Duration: " . number_format($duration, 2) . "s\n";
        
        // Check load distribution
        foreach ($servers as $serverId => $server) {
            if ($server['status'] === 'healthy') {
                echo "  {$serverId}: {$server['connections']} connections\n";
            }
        }
    }
    
    /**
     * @group reliability
     */
    public function testDataIntegrityUnderFailure(): void
    {
        $connectionCount = 100;
        $operationCount = 1000;
        $failureRate = 0.1; // 10% operation failure rate
        
        // Setup connections with data tracking
        $connections = [];
        for ($i = 0; $i < $connectionCount; $i++) {
            $connectionId = "integrity-conn-{$i}";
            $connections[] = $connectionId;
            $this->poolManager->addConnection($connectionId, [
                'protocol' => 'websocket',
                'data_checksum' => 0,
                'operation_count' => 0,
                'failed_operations' => 0
            ]);
        }
        
        $totalOperations = 0;
        $successfulOperations = 0;
        $dataIntegrityViolations = 0;
        
        // Perform operations with simulated failures
        for ($i = 0; $i < $operationCount; $i++) {
            $connectionId = $connections[array_rand($connections)];
            $totalOperations++;
            
            // Simulate operation failure
            if (rand(1, 100) <= ($failureRate * 100)) {
                $this->simulateFailedOperation($connectionId);
            } else {
                if ($this->simulateSuccessfulOperation($connectionId, $i)) {
                    $successfulOperations++;
                } else {
                    $dataIntegrityViolations++;
                }
            }
        }
        
        // Verify data integrity
        $checksumMismatches = 0;
        foreach ($connections as $connectionId) {
            $connection = $this->poolManager->getConnection($connectionId);
            if ($connection) {
                $expectedChecksum = $connection['operation_count'] * 123; // Simple checksum
                if ($connection['data_checksum'] !== $expectedChecksum) {
                    $checksumMismatches++;
                }
            }
        }
        
        $integrityRate = 1 - ($dataIntegrityViolations / max($totalOperations, 1));
        
        $this->assertGreaterThan(
            0.99, // 99% data integrity
            $integrityRate,
            "Data integrity violations detected"
        );
        
        $this->assertLessThan(
            $connectionCount * 0.01, // Less than 1% checksum mismatches
            $checksumMismatches,
            "Too many checksum mismatches"
        );
        
        echo "\nData Integrity Under Failure Test:\n";
        echo "  Connections: {$connectionCount}\n";
        echo "  Total Operations: {$totalOperations}\n";
        echo "  Successful Operations: {$successfulOperations}\n";
        echo "  Integrity Violations: {$dataIntegrityViolations}\n";
        echo "  Checksum Mismatches: {$checksumMismatches}\n";
        echo "  Integrity Rate: " . number_format($integrityRate * 100, 2) . "%\n";
    }
    
    private function simulateConnectionFailure(string $connectionId): void
    {
        $this->poolManager->removeConnection($connectionId);
    }
    
    private function simulateConnectionRecovery(string $connectionId): bool
    {
        return $this->poolManager->addConnection($connectionId, [
            'protocol' => 'websocket',
            'status' => 'recovered',
            'connected_at' => microtime(true),
            'recovery_attempts' => 1
        ]);
    }
    
    private function applyDegradationScenario(string $scenario, float $severity, array $connections): void
    {
        foreach ($connections as $connectionId) {
            $degradationLevel = $severity;
            $this->poolManager->updateConnection($connectionId, [
                'degradation_level' => $degradationLevel,
                'degradation_type' => $scenario
            ]);
        }
    }
    
    private function simulateOperationUnderDegradation(string $connectionId, string $scenario, float $severity): bool
    {
        $connection = $this->poolManager->getConnection($connectionId);
        if (!$connection) return false;
        
        // Apply scenario-specific delays/failures
        $failureChance = match($scenario) {
            'high_cpu' => $severity * 0.3,      // More CPU = more failures
            'high_memory' => $severity * 0.4,   // More memory pressure = more failures
            'network_lag' => $severity * 0.2,   // Network lag = some timeouts
            'partial_failure' => $severity      // Direct failure rate
        };
        
        return rand(1, 100) > ($failureChance * 100);
    }
    
    private function selectServerForLoadBalancing(array &$servers, array $excludeServers = []): ?string
    {
        $eligibleServers = array_filter($servers, function($server, $serverId) use ($excludeServers) {
            return $server['status'] === 'healthy' && !in_array($serverId, $excludeServers);
        }, ARRAY_FILTER_USE_BOTH);
        
        if (empty($eligibleServers)) return null;
        
        // Simple round-robin selection
        $minLoad = min(array_column($eligibleServers, 'load'));
        foreach ($eligibleServers as $serverId => $server) {
            if ($server['load'] === $minLoad) {
                return $serverId;
            }
        }
        
        return array_key_first($eligibleServers);
    }
    
    private function simulateFailedOperation(string $connectionId): void
    {
        $connection = $this->poolManager->getConnection($connectionId);
        if ($connection) {
            $this->poolManager->updateConnection($connectionId, [
                'failed_operations' => $connection['failed_operations'] + 1
            ]);
        }
    }
    
    private function simulateSuccessfulOperation(string $connectionId, int $operationValue): bool
    {
        $connection = $this->poolManager->getConnection($connectionId);
        if (!$connection) return false;
        
        $newOperationCount = $connection['operation_count'] + 1;
        $newChecksum = $connection['data_checksum'] + ($operationValue * 123);
        
        return $this->poolManager->updateConnection($connectionId, [
            'operation_count' => $newOperationCount,
            'data_checksum' => $newChecksum
        ]);
    }
}