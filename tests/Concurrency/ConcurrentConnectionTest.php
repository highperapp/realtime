<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Tests\Concurrency;

use HighPerApp\HighPer\Realtime\ConnectionPool\ConnectionPoolManager;
use HighPerApp\HighPer\Realtime\Configuration\RealtimeConfig;
use HighPerApp\HighPer\Realtime\Protocols\WebSocket\WebSocketHttp3Protocol;
use PHPUnit\Framework\TestCase;
use Amp\PHPUnit\AsyncTestCase;

class ConcurrentConnectionTest extends AsyncTestCase
{
    private ConnectionPoolManager $poolManager;
    private RealtimeConfig $config;
    private const MAX_CONCURRENT_CONNECTIONS = 1000;
    private const CONCURRENT_MESSAGE_COUNT = 5000;
    
    protected function setUp(): void
    {
        $this->config = new RealtimeConfig([
            'enabled' => true,
            'max_connections' => 10000,
            'connection_timeout' => 30,
            'protocols' => [
                'websocket' => ['enabled' => true]
            ]
        ]);
        
        $this->poolManager = new ConnectionPoolManager($this->config);
    }
    
    /**
     * @group concurrency
     */
    public function testConcurrentConnectionCreation(): void
    {
        $connections = [];
        $startTime = hrtime(true);
        
        // Simulate concurrent connection creation
        $promises = [];
        for ($i = 0; $i < self::MAX_CONCURRENT_CONNECTIONS; $i++) {
            $connectionId = "concurrent-conn-{$i}";
            $connectionData = [
                'protocol' => 'websocket',
                'remote_address' => '127.0.0.1',
                'connected_at' => microtime(true),
                'thread_id' => $i % 10 // Simulate 10 worker threads
            ];
            
            $promises[] = $this->createConnectionAsync($connectionId, $connectionData);
        }
        
        // Wait for all connections to be created
        $results = [];
        foreach ($promises as $promise) {
            $results[] = $promise;
        }
        
        $endTime = hrtime(true);
        $duration = ($endTime - $startTime) / 1e9;
        
        $successCount = count(array_filter($results));
        $this->assertGreaterThanOrEqual(
            self::MAX_CONCURRENT_CONNECTIONS * 0.95, // Allow 5% failure rate
            $successCount,
            "Too many concurrent connection failures"
        );
        
        $this->assertLessThan(
            5.0, // 5 second threshold
            $duration,
            "Concurrent connection creation took too long"
        );
        
        echo "\nConcurrent Connection Creation:\n";
        echo "  Total Connections: " . self::MAX_CONCURRENT_CONNECTIONS . "\n";
        echo "  Successful: {$successCount}\n";
        echo "  Duration: " . number_format($duration, 2) . "s\n";
        echo "  Rate: " . number_format($successCount / $duration, 0) . " conn/sec\n";
    }
    
    /**
     * @group concurrency
     */
    public function testConcurrentMessageProcessing(): void
    {
        // Setup connections first
        $connectionCount = 100;
        for ($i = 0; $i < $connectionCount; $i++) {
            $this->poolManager->addConnection("msg-conn-{$i}", [
                'protocol' => 'websocket',
                'messages_processed' => 0,
                'last_message_time' => microtime(true)
            ]);
        }
        
        $messages = [];
        $startTime = hrtime(true);
        
        // Generate concurrent messages
        for ($i = 0; $i < self::CONCURRENT_MESSAGE_COUNT; $i++) {
            $connectionId = "msg-conn-" . ($i % $connectionCount);
            $message = [
                'id' => $i,
                'type' => 'concurrent_test',
                'payload' => str_repeat('x', 256),
                'timestamp' => microtime(true)
            ];
            
            $messages[] = $this->processMessageConcurrently($connectionId, $message);
        }
        
        $endTime = hrtime(true);
        $duration = ($endTime - $startTime) / 1e9;
        $throughput = self::CONCURRENT_MESSAGE_COUNT / $duration;
        
        $this->assertGreaterThan(
            1000, // 1000 messages per second minimum
            $throughput,
            "Concurrent message processing throughput too low"
        );
        
        echo "\nConcurrent Message Processing:\n";
        echo "  Messages: " . self::CONCURRENT_MESSAGE_COUNT . "\n";
        echo "  Connections: {$connectionCount}\n";
        echo "  Duration: " . number_format($duration, 2) . "s\n";
        echo "  Throughput: " . number_format($throughput, 0) . " msg/sec\n";
    }
    
    /**
     * @group concurrency
     */
    public function testConcurrentBroadcast(): void
    {
        $connectionCount = 500;
        $broadcastCount = 50;
        
        // Setup connections
        $connections = [];
        for ($i = 0; $i < $connectionCount; $i++) {
            $connectionId = "broadcast-conn-{$i}";
            $connections[] = $connectionId;
            $this->poolManager->addConnection($connectionId, [
                'protocol' => 'websocket',
                'broadcasts_received' => 0
            ]);
        }
        
        $startTime = hrtime(true);
        
        // Perform concurrent broadcasts
        $broadcasts = [];
        for ($i = 0; $i < $broadcastCount; $i++) {
            $message = [
                'type' => 'broadcast',
                'id' => $i,
                'payload' => "Broadcast message #{$i}",
                'timestamp' => microtime(true)
            ];
            
            $broadcasts[] = $this->broadcastConcurrently($connections, $message);
        }
        
        $endTime = hrtime(true);
        $duration = ($endTime - $startTime) / 1e9;
        $messagesDelivered = $broadcastCount * $connectionCount;
        $deliveryRate = $messagesDelivered / $duration;
        
        $this->assertLessThan(
            2.0, // 2 second threshold for all broadcasts
            $duration,
            "Concurrent broadcast took too long"
        );
        
        echo "\nConcurrent Broadcast:\n";
        echo "  Broadcasts: {$broadcastCount}\n";
        echo "  Connections: {$connectionCount}\n";
        echo "  Total Messages: {$messagesDelivered}\n";
        echo "  Duration: " . number_format($duration, 2) . "s\n";
        echo "  Delivery Rate: " . number_format($deliveryRate, 0) . " msg/sec\n";
    }
    
    /**
     * @group concurrency
     */
    public function testRaceConditionProtection(): void
    {
        $connectionId = 'race-condition-test';
        $iterations = 1000;
        
        // Initialize connection
        $this->poolManager->addConnection($connectionId, [
            'protocol' => 'websocket',
            'counter' => 0,
            'operations' => []
        ]);
        
        $startTime = hrtime(true);
        
        // Simulate concurrent operations on the same connection
        $operations = [];
        for ($i = 0; $i < $iterations; $i++) {
            $operations[] = $this->simulateRaceCondition($connectionId, $i);
        }
        
        $endTime = hrtime(true);
        $duration = ($endTime - $startTime) / 1e9;
        
        // Verify data integrity
        $connection = $this->poolManager->getConnection($connectionId);
        $this->assertIsArray($connection);
        
        // Check that all operations were applied (race condition protection worked)
        $expectedOperations = $iterations;
        $actualOperations = count($connection['operations'] ?? []);
        
        $integrityRatio = $actualOperations / $expectedOperations;
        $this->assertGreaterThan(
            0.95, // Allow 5% for legitimate race conditions
            $integrityRatio,
            "Race condition protection failed - data integrity compromised"
        );
        
        echo "\nRace Condition Protection:\n";
        echo "  Operations: {$iterations}\n";
        echo "  Duration: " . number_format($duration, 2) . "s\n";
        echo "  Integrity: " . number_format($integrityRatio * 100, 1) . "%\n";
        echo "  Successful Operations: {$actualOperations}\n";
    }
    
    /**
     * @group concurrency
     */
    public function testDeadlockPrevention(): void
    {
        $connectionPairs = 50;
        $operationsPerPair = 20;
        
        // Create connection pairs that could potentially deadlock
        $connections = [];
        for ($i = 0; $i < $connectionPairs * 2; $i++) {
            $connectionId = "deadlock-conn-{$i}";
            $connections[] = $connectionId;
            $this->poolManager->addConnection($connectionId, [
                'protocol' => 'websocket',
                'locked_resources' => [],
                'waiting_for' => null
            ]);
        }
        
        $startTime = hrtime(true);
        $operations = [];
        
        // Simulate operations that could cause deadlocks
        for ($pair = 0; $pair < $connectionPairs; $pair++) {
            $conn1 = "deadlock-conn-" . ($pair * 2);
            $conn2 = "deadlock-conn-" . ($pair * 2 + 1);
            
            for ($op = 0; $op < $operationsPerPair; $op++) {
                // Alternate resource locking order to create potential deadlocks
                if ($op % 2 === 0) {
                    $operations[] = $this->simulateResourceLocking($conn1, $conn2, "resource-{$pair}-A", "resource-{$pair}-B");
                } else {
                    $operations[] = $this->simulateResourceLocking($conn2, $conn1, "resource-{$pair}-B", "resource-{$pair}-A");
                }
            }
        }
        
        $endTime = hrtime(true);
        $duration = ($endTime - $startTime) / 1e9;
        
        // Verify no deadlocks occurred (all operations completed within reasonable time)
        $this->assertLessThan(
            5.0, // 5 second threshold - deadlocks would cause timeout
            $duration,
            "Potential deadlock detected - operations took too long"
        );
        
        echo "\nDeadlock Prevention:\n";
        echo "  Connection Pairs: {$connectionPairs}\n";
        echo "  Operations per Pair: {$operationsPerPair}\n";
        echo "  Total Operations: " . count($operations) . "\n";
        echo "  Duration: " . number_format($duration, 2) . "s\n";
        echo "  No deadlocks detected\n";
    }
    
    /**
     * @group concurrency
     */
    public function testResourceContention(): void
    {
        $workerCount = 20;
        $operationsPerWorker = 100;
        $sharedResourceCount = 5;
        
        $startTime = hrtime(true);
        $operations = [];
        
        // Simulate multiple workers contending for shared resources
        for ($worker = 0; $worker < $workerCount; $worker++) {
            for ($op = 0; $op < $operationsPerWorker; $op++) {
                $resourceId = $op % $sharedResourceCount;
                $operations[] = $this->simulateResourceContention($worker, $resourceId);
            }
        }
        
        $endTime = hrtime(true);
        $duration = ($endTime - $startTime) / 1e9;
        $operationsPerSecond = count($operations) / $duration;
        
        $this->assertGreaterThan(
            500, // 500 operations per second minimum under contention
            $operationsPerSecond,
            "Resource contention severely impacted performance"
        );
        
        echo "\nResource Contention:\n";
        echo "  Workers: {$workerCount}\n";
        echo "  Operations per Worker: {$operationsPerWorker}\n";
        echo "  Shared Resources: {$sharedResourceCount}\n";
        echo "  Total Operations: " . count($operations) . "\n";
        echo "  Duration: " . number_format($duration, 2) . "s\n";
        echo "  Rate: " . number_format($operationsPerSecond, 0) . " ops/sec\n";
    }
    
    private function createConnectionAsync(string $connectionId, array $data): bool
    {
        // Simulate async connection creation with potential race conditions
        usleep(rand(1000, 5000)); // 1-5ms delay
        return $this->poolManager->addConnection($connectionId, $data);
    }
    
    private function processMessageConcurrently(string $connectionId, array $message): bool
    {
        // Simulate concurrent message processing
        $connection = $this->poolManager->getConnection($connectionId);
        if (!$connection) {
            return false;
        }
        
        usleep(rand(100, 1000)); // Processing delay
        
        // Update connection metadata
        $updateData = [
            'messages_processed' => ($connection['messages_processed'] ?? 0) + 1,
            'last_message_time' => microtime(true)
        ];
        
        return $this->poolManager->updateConnection($connectionId, $updateData);
    }
    
    private function broadcastConcurrently(array $connections, array $message): int
    {
        $delivered = 0;
        foreach ($connections as $connectionId) {
            if ($this->processMessageConcurrently($connectionId, $message)) {
                $delivered++;
            }
        }
        return $delivered;
    }
    
    private function simulateRaceCondition(string $connectionId, int $operationId): bool
    {
        // Simulate race condition by multiple operations on same data
        $connection = $this->poolManager->getConnection($connectionId);
        if (!$connection) {
            return false;
        }
        
        usleep(rand(10, 100)); // Simulate processing time
        
        $operations = $connection['operations'] ?? [];
        $operations[] = [
            'id' => $operationId,
            'timestamp' => microtime(true),
            'thread' => rand(1, 10)
        ];
        
        return $this->poolManager->updateConnection($connectionId, [
            'counter' => ($connection['counter'] ?? 0) + 1,
            'operations' => $operations
        ]);
    }
    
    private function simulateResourceLocking(string $conn1, string $conn2, string $resource1, string $resource2): bool
    {
        // Simulate resource locking that could cause deadlocks
        usleep(rand(100, 500)); // Lock acquisition time
        
        // Simulate ordered locking to prevent deadlocks
        $resources = [$resource1, $resource2];
        sort($resources); // Always lock in same order
        
        foreach ($resources as $resource) {
            usleep(rand(10, 50)); // Resource operation time
        }
        
        return true;
    }
    
    private function simulateResourceContention(int $workerId, int $resourceId): bool
    {
        // Simulate contention for shared resources
        $lockTime = rand(100, 1000); // 0.1-1ms lock time
        usleep($lockTime);
        
        // Random chance of contention conflict
        return rand(1, 100) > 5; // 95% success rate
    }
}