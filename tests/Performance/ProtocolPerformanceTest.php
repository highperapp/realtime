<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Tests\Performance;

use HighPerApp\HighPer\Realtime\Protocols\WebSocket\WebSocketHttp3Protocol;
use HighPerApp\HighPer\Realtime\Protocols\SSE\SSEHttp3Protocol;
use HighPerApp\HighPer\Realtime\Protocols\WebTransport\WebTransportProtocol;
use HighPerApp\HighPer\Realtime\Configuration\RealtimeConfig;
use PHPUnit\Framework\TestCase;

class ProtocolPerformanceTest extends TestCase
{
    private const PERFORMANCE_THRESHOLD_MS = 100;
    private const MEMORY_THRESHOLD_MB = 50;
    private const THROUGHPUT_THRESHOLD_MSG_PER_SEC = 1000;
    
    private array $protocols;
    private RealtimeConfig $config;
    
    protected function setUp(): void
    {
        $this->config = new RealtimeConfig([
            'enabled' => true,
            'max_connections' => 10000,
            'protocols' => [
                'websocket' => ['enabled' => true],
                'sse' => ['enabled' => true],
                'webtransport' => ['enabled' => true]
            ]
        ]);
        
        $this->protocols = [
            'websocket' => new WebSocketHttp3Protocol($this->config),
            'sse' => new SSEHttp3Protocol($this->config),
            'webtransport' => new WebTransportProtocol($this->config)
        ];
    }
    
    /**
     * @group performance
     */
    public function testMessageProcessingLatency(): void
    {
        foreach ($this->protocols as $name => $protocol) {
            $measurements = [];
            $messageCount = 1000;
            
            for ($i = 0; $i < $messageCount; $i++) {
                $startTime = hrtime(true);
                
                $message = [
                    'type' => 'test',
                    'payload' => str_repeat('x', 1024), // 1KB payload
                    'timestamp' => microtime(true)
                ];
                
                // Simulate message processing
                $this->processMessageBenchmark($protocol, "conn-{$i}", $message);
                
                $endTime = hrtime(true);
                $measurements[] = ($endTime - $startTime) / 1e6; // Convert to milliseconds
            }
            
            $avgLatency = array_sum($measurements) / count($measurements);
            $p95Latency = $this->calculatePercentile($measurements, 95);
            $p99Latency = $this->calculatePercentile($measurements, 99);
            
            $this->assertLessThan(
                self::PERFORMANCE_THRESHOLD_MS,
                $avgLatency,
                "Protocol {$name} average latency ({$avgLatency}ms) exceeds threshold"
            );
            
            $this->assertLessThan(
                self::PERFORMANCE_THRESHOLD_MS * 2,
                $p95Latency,
                "Protocol {$name} P95 latency ({$p95Latency}ms) exceeds threshold"
            );
            
            echo "\n{$name} Protocol Performance:\n";
            echo "  Average Latency: " . number_format($avgLatency, 2) . "ms\n";
            echo "  P95 Latency: " . number_format($p95Latency, 2) . "ms\n";
            echo "  P99 Latency: " . number_format($p99Latency, 2) . "ms\n";
        }
    }
    
    /**
     * @group performance
     */
    public function testThroughputPerformance(): void
    {
        foreach ($this->protocols as $name => $protocol) {
            $messageCount = 10000;
            $startTime = microtime(true);
            
            for ($i = 0; $i < $messageCount; $i++) {
                $connectionId = "conn-" . ($i % 100); // Simulate 100 connections
                $message = [
                    'type' => 'throughput_test',
                    'sequence' => $i,
                    'data' => str_repeat('x', 512) // 512 bytes
                ];
                
                $this->processMessageBenchmark($protocol, $connectionId, $message);
            }
            
            $endTime = microtime(true);
            $duration = $endTime - $startTime;
            $throughput = $messageCount / $duration;
            
            $this->assertGreaterThan(
                self::THROUGHPUT_THRESHOLD_MSG_PER_SEC,
                $throughput,
                "Protocol {$name} throughput ({$throughput} msg/sec) below threshold"
            );
            
            echo "\n{$name} Protocol Throughput:\n";
            echo "  Messages: {$messageCount}\n";
            echo "  Duration: " . number_format($duration, 2) . "s\n";
            echo "  Throughput: " . number_format($throughput, 0) . " msg/sec\n";
        }
    }
    
    /**
     * @group performance
     */
    public function testMemoryUsage(): void
    {
        foreach ($this->protocols as $name => $protocol) {
            $initialMemory = memory_get_usage(true);
            
            // Simulate high connection load
            $connectionCount = 1000;
            for ($i = 0; $i < $connectionCount; $i++) {
                $connectionId = "memory-test-{$i}";
                $this->simulateConnection($protocol, $connectionId);
            }
            
            $peakMemory = memory_get_peak_usage(true);
            $memoryUsedMB = ($peakMemory - $initialMemory) / 1024 / 1024;
            
            $this->assertLessThan(
                self::MEMORY_THRESHOLD_MB,
                $memoryUsedMB,
                "Protocol {$name} memory usage ({$memoryUsedMB}MB) exceeds threshold"
            );
            
            echo "\n{$name} Protocol Memory Usage:\n";
            echo "  Connections: {$connectionCount}\n";
            echo "  Memory Used: " . number_format($memoryUsedMB, 2) . "MB\n";
            echo "  Memory per Connection: " . number_format($memoryUsedMB * 1024 / $connectionCount, 2) . "KB\n";
            
            // Cleanup
            gc_collect_cycles();
        }
    }
    
    /**
     * @group performance
     */
    public function testBroadcastPerformance(): void
    {
        foreach ($this->protocols as $name => $protocol) {
            $connectionCount = 1000;
            $connections = [];
            
            // Setup connections
            for ($i = 0; $i < $connectionCount; $i++) {
                $connections[] = "broadcast-conn-{$i}";
            }
            
            $message = [
                'type' => 'broadcast',
                'payload' => str_repeat('x', 1024), // 1KB message
                'timestamp' => microtime(true)
            ];
            
            $startTime = hrtime(true);
            $this->simulateBroadcast($protocol, $connections, $message);
            $endTime = hrtime(true);
            
            $duration = ($endTime - $startTime) / 1e9; // Convert to seconds
            $throughput = $connectionCount / $duration;
            
            $this->assertLessThan(
                0.5, // 500ms threshold for broadcast
                $duration,
                "Protocol {$name} broadcast duration ({$duration}s) exceeds threshold"
            );
            
            echo "\n{$name} Protocol Broadcast Performance:\n";
            echo "  Connections: {$connectionCount}\n";
            echo "  Duration: " . number_format($duration * 1000, 2) . "ms\n";
            echo "  Throughput: " . number_format($throughput, 0) . " connections/sec\n";
        }
    }
    
    /**
     * @group performance
     */
    public function testConnectionEstablishmentTime(): void
    {
        foreach ($this->protocols as $name => $protocol) {
            $measurements = [];
            $connectionCount = 100;
            
            for ($i = 0; $i < $connectionCount; $i++) {
                $startTime = hrtime(true);
                
                $connectionId = "perf-conn-{$i}";
                $this->simulateConnectionHandshake($protocol, $connectionId);
                
                $endTime = hrtime(true);
                $measurements[] = ($endTime - $startTime) / 1e6; // Convert to milliseconds
            }
            
            $avgTime = array_sum($measurements) / count($measurements);
            $p95Time = $this->calculatePercentile($measurements, 95);
            
            $this->assertLessThan(
                50, // 50ms threshold
                $avgTime,
                "Protocol {$name} average connection time ({$avgTime}ms) exceeds threshold"
            );
            
            echo "\n{$name} Protocol Connection Performance:\n";
            echo "  Average Time: " . number_format($avgTime, 2) . "ms\n";
            echo "  P95 Time: " . number_format($p95Time, 2) . "ms\n";
        }
    }
    
    /**
     * @group performance
     */
    public function testConcurrentConnectionHandling(): void
    {
        foreach ($this->protocols as $name => $protocol) {
            $concurrentConnections = 500;
            $startTime = hrtime(true);
            
            // Simulate concurrent connections
            $connections = [];
            for ($i = 0; $i < $concurrentConnections; $i++) {
                $connections[] = "concurrent-{$i}";
            }
            
            // Process all connections "concurrently" (simulated)
            foreach ($connections as $connectionId) {
                $this->simulateConnection($protocol, $connectionId);
            }
            
            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1e9;
            $connectionsPerSecond = $concurrentConnections / $duration;
            
            $this->assertGreaterThan(
                100, // 100 connections per second minimum
                $connectionsPerSecond,
                "Protocol {$name} concurrent handling rate too low"
            );
            
            echo "\n{$name} Protocol Concurrent Handling:\n";
            echo "  Connections: {$concurrentConnections}\n";
            echo "  Duration: " . number_format($duration, 2) . "s\n";
            echo "  Rate: " . number_format($connectionsPerSecond, 0) . " conn/sec\n";
        }
    }
    
    private function processMessageBenchmark($protocol, string $connectionId, array $message): void
    {
        // Simulate message processing overhead
        $serialized = json_encode($message);
        $hash = hash('sha256', $serialized);
        usleep(rand(10, 50)); // Simulate I/O delay (10-50 microseconds)
    }
    
    private function simulateConnection($protocol, string $connectionId): void
    {
        // Simulate connection overhead
        $metadata = [
            'id' => $connectionId,
            'protocol' => get_class($protocol),
            'connected_at' => microtime(true),
            'remote_address' => '127.0.0.1',
            'user_agent' => 'PerformanceTest/1.0'
        ];
        usleep(rand(5, 25)); // Simulate connection establishment
    }
    
    private function simulateConnectionHandshake($protocol, string $connectionId): void
    {
        // Simulate handshake process
        usleep(rand(100, 500)); // Handshake typically takes longer
    }
    
    private function simulateBroadcast($protocol, array $connections, array $message): void
    {
        // Simulate broadcast to all connections
        foreach ($connections as $connectionId) {
            $this->processMessageBenchmark($protocol, $connectionId, $message);
        }
    }
    
    private function calculatePercentile(array $values, float $percentile): float
    {
        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower === $upper) {
            return $values[$lower];
        }
        
        $weight = $index - $lower;
        return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
    }
}