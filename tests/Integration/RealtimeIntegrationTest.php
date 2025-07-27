<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Tests\Integration;

use HighPerApp\HighPer\Realtime\RealtimeServiceProvider;
use HighPerApp\HighPer\Realtime\Configuration\RealtimeConfig;
use HighPerApp\HighPer\Realtime\ConnectionPool\ConnectionPoolManager;
use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class RealtimeIntegrationTest extends TestCase
{
    private RealtimeServiceProvider $serviceProvider;
    private MockObject $container;
    private MockObject $application;
    private RealtimeConfig $config;
    
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->application = $this->createMock(Application::class);
        
        $this->config = new RealtimeConfig([
            'enabled' => true,
            'auto_start' => true,
            'host' => '127.0.0.1',
            'port' => 8080,
            'protocols' => [
                'websocket' => ['enabled' => true, 'path' => '/ws'],
                'sse' => ['enabled' => true, 'path' => '/sse'],
                'webtransport' => ['enabled' => true, 'path' => '/wt']
            ]
        ]);
        
        $this->serviceProvider = new RealtimeServiceProvider();
    }
    
    public function testServiceProviderRegistration(): void
    {
        $this->container->expects($this->atLeastOnce())
            ->method('bind')
            ->with($this->isType('string'), $this->isType('callable'));
            
        $this->serviceProvider->register($this->container);
        
        $this->assertTrue(true); // Service provider registered without errors
    }
    
    public function testServiceProviderBootWithEnabledRealtime(): void
    {
        // Setup container expectations
        $this->container->method('get')
            ->willReturnMap([
                [RealtimeConfig::class, $this->config],
                [ConnectionPoolManager::class, new ConnectionPoolManager($this->config)]
            ]);
            
        $this->application->expects($this->atLeastOnce())
            ->method('addMiddleware')
            ->with($this->isType('callable'));
            
        $this->serviceProvider->boot($this->application);
        
        $this->assertTrue(true); // Service provider booted without errors
    }
    
    public function testServiceProviderSkipsWhenDisabled(): void
    {
        $disabledConfig = new RealtimeConfig(['enabled' => false]);
        
        $this->container->method('get')
            ->with(RealtimeConfig::class)
            ->willReturn($disabledConfig);
            
        // Should not register any services when disabled
        $this->container->expects($this->never())
            ->method('bind');
            
        $this->serviceProvider->register($this->container);
    }
    
    public function testWebSocketProtocolIntegration(): void
    {
        $connectionManager = new ConnectionPoolManager($this->config);
        
        // Test WebSocket connection lifecycle
        $connectionId = 'ws-integration-test';
        $connectionData = [
            'protocol' => 'websocket',
            'path' => '/ws',
            'remote_address' => '127.0.0.1',
            'headers' => [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => base64_encode(random_bytes(16))
            ]
        ];
        
        // Add connection
        $this->assertTrue($connectionManager->addConnection($connectionId, $connectionData));
        $this->assertTrue($connectionManager->hasConnection($connectionId));
        
        // Update connection with message data
        $messageData = [
            'messages_sent' => 5,
            'messages_received' => 3,
            'last_activity' => microtime(true)
        ];
        $this->assertTrue($connectionManager->updateConnection($connectionId, $messageData));
        
        // Verify connection data
        $connection = $connectionManager->getConnection($connectionId);
        $this->assertSame('websocket', $connection['protocol']);
        $this->assertSame(5, $connection['messages_sent']);
        $this->assertSame(3, $connection['messages_received']);
        
        // Remove connection
        $this->assertTrue($connectionManager->removeConnection($connectionId));
        $this->assertFalse($connectionManager->hasConnection($connectionId));
    }
    
    public function testSSEProtocolIntegration(): void
    {
        $connectionManager = new ConnectionPoolManager($this->config);
        
        // Test SSE connection lifecycle
        $connectionId = 'sse-integration-test';
        $connectionData = [
            'protocol' => 'sse',
            'path' => '/sse',
            'remote_address' => '192.168.1.100',
            'headers' => [
                'Accept' => 'text/event-stream',
                'Cache-Control' => 'no-cache'
            ]
        ];
        
        $this->assertTrue($connectionManager->addConnection($connectionId, $connectionData));
        
        // Simulate SSE event sending
        $events = [
            ['type' => 'message', 'data' => 'Hello SSE'],
            ['type' => 'update', 'data' => json_encode(['status' => 'online'])],
            ['type' => 'notification', 'data' => 'New notification']
        ];
        
        foreach ($events as $event) {
            $updateData = [
                'last_event_type' => $event['type'],
                'last_event_data' => $event['data'],
                'events_sent' => ($connectionManager->getConnection($connectionId)['events_sent'] ?? 0) + 1
            ];
            $this->assertTrue($connectionManager->updateConnection($connectionId, $updateData));
        }
        
        $connection = $connectionManager->getConnection($connectionId);
        $this->assertSame('sse', $connection['protocol']);
        $this->assertSame(3, $connection['events_sent']);
        $this->assertSame('notification', $connection['last_event_type']);
    }
    
    public function testWebTransportProtocolIntegration(): void
    {
        $connectionManager = new ConnectionPoolManager($this->config);
        
        $connectionId = 'wt-integration-test';
        $connectionData = [
            'protocol' => 'webtransport',
            'path' => '/wt',
            'remote_address' => '10.0.0.50',
            'session_id' => bin2hex(random_bytes(16)),
            'streams' => [],
            'datagrams_sent' => 0,
            'datagrams_received' => 0
        ];
        
        $this->assertTrue($connectionManager->addConnection($connectionId, $connectionData));
        
        // Simulate WebTransport stream operations
        $streamIds = [];
        for ($i = 0; $i < 5; $i++) {
            $streamIds[] = "stream-{$i}";
        }
        
        $updateData = [
            'streams' => $streamIds,
            'active_streams' => count($streamIds),
            'datagrams_sent' => 25,
            'datagrams_received' => 18
        ];
        
        $this->assertTrue($connectionManager->updateConnection($connectionId, $updateData));
        
        $connection = $connectionManager->getConnection($connectionId);
        $this->assertSame('webtransport', $connection['protocol']);
        $this->assertSame(5, $connection['active_streams']);
        $this->assertSame(25, $connection['datagrams_sent']);
        $this->assertSame(18, $connection['datagrams_received']);
    }
    
    public function testMultiProtocolBroadcast(): void
    {
        $connectionManager = new ConnectionPoolManager($this->config);
        
        // Setup connections for different protocols
        $connections = [
            'ws-broadcast-1' => ['protocol' => 'websocket'],
            'ws-broadcast-2' => ['protocol' => 'websocket'],
            'sse-broadcast-1' => ['protocol' => 'sse'],
            'sse-broadcast-2' => ['protocol' => 'sse'],
            'wt-broadcast-1' => ['protocol' => 'webtransport']
        ];
        
        foreach ($connections as $connectionId => $data) {
            $data['broadcasts_received'] = 0;
            $data['connected_at'] = microtime(true);
            $this->assertTrue($connectionManager->addConnection($connectionId, $data));
        }
        
        // Test protocol-specific broadcast
        $websocketConnections = $connectionManager->getConnectionsByProtocol('websocket');
        $this->assertCount(2, $websocketConnections);
        
        $sseConnections = $connectionManager->getConnectionsByProtocol('sse');
        $this->assertCount(2, $sseConnections);
        
        $webtransportConnections = $connectionManager->getConnectionsByProtocol('webtransport');
        $this->assertCount(1, $webtransportConnections);
        
        // Simulate broadcast to WebSocket connections only
        $broadcastMessage = ['type' => 'announcement', 'message' => 'WebSocket only broadcast'];
        foreach (array_keys($websocketConnections) as $connectionId) {
            $connection = $connectionManager->getConnection($connectionId);
            $updateData = [
                'broadcasts_received' => $connection['broadcasts_received'] + 1,
                'last_broadcast' => $broadcastMessage
            ];
            $this->assertTrue($connectionManager->updateConnection($connectionId, $updateData));
        }
        
        // Verify only WebSocket connections received the broadcast
        foreach ($connections as $connectionId => $originalData) {
            $connection = $connectionManager->getConnection($connectionId);
            if ($originalData['protocol'] === 'websocket') {
                $this->assertSame(1, $connection['broadcasts_received']);
            } else {
                $this->assertSame(0, $connection['broadcasts_received']);
            }
        }
    }
    
    public function testConnectionPoolLimits(): void
    {
        $limitedConfig = new RealtimeConfig([
            'enabled' => true,
            'max_connections' => 5
        ]);
        
        $connectionManager = new ConnectionPoolManager($limitedConfig);
        
        // Add connections up to the limit
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($connectionManager->addConnection("conn-{$i}", [
                'protocol' => 'websocket',
                'index' => $i
            ]));
        }
        
        $this->assertSame(5, $connectionManager->getConnectionCount());
        
        // Try to add one more connection (should fail)
        $this->assertFalse($connectionManager->addConnection('conn-overflow', [
            'protocol' => 'websocket'
        ]));
        
        $this->assertSame(5, $connectionManager->getConnectionCount());
        
        // Remove one connection and try again
        $this->assertTrue($connectionManager->removeConnection('conn-0'));
        $this->assertSame(4, $connectionManager->getConnectionCount());
        
        $this->assertTrue($connectionManager->addConnection('conn-replacement', [
            'protocol' => 'sse'
        ]));
        
        $this->assertSame(5, $connectionManager->getConnectionCount());
    }
    
    public function testConnectionCleanup(): void
    {
        $connectionManager = new ConnectionPoolManager($this->config);
        
        $now = time();
        $connections = [
            'active-conn-1' => [
                'protocol' => 'websocket',
                'last_activity' => $now - 100 // 100 seconds ago
            ],
            'active-conn-2' => [
                'protocol' => 'sse',
                'last_activity' => $now - 50 // 50 seconds ago
            ],
            'expired-conn-1' => [
                'protocol' => 'websocket',
                'last_activity' => $now - 400 // 400 seconds ago (expired)
            ],
            'expired-conn-2' => [
                'protocol' => 'webtransport',
                'last_activity' => $now - 500 // 500 seconds ago (expired)
            ]
        ];
        
        foreach ($connections as $connectionId => $data) {
            $this->assertTrue($connectionManager->addConnection($connectionId, $data));
        }
        
        $this->assertSame(4, $connectionManager->getConnectionCount());
        
        // Clean up connections older than 300 seconds
        $cleanedCount = $connectionManager->cleanupExpiredConnections(300);
        
        $this->assertSame(2, $cleanedCount);
        $this->assertSame(2, $connectionManager->getConnectionCount());
        
        // Verify correct connections remain
        $this->assertTrue($connectionManager->hasConnection('active-conn-1'));
        $this->assertTrue($connectionManager->hasConnection('active-conn-2'));
        $this->assertFalse($connectionManager->hasConnection('expired-conn-1'));
        $this->assertFalse($connectionManager->hasConnection('expired-conn-2'));
    }
    
    public function testConnectionStatistics(): void
    {
        $connectionManager = new ConnectionPoolManager($this->config);
        
        // Add various connections with different protocols and data
        $connections = [
            'ws-1' => ['protocol' => 'websocket', 'bytes_sent' => 1024, 'messages_sent' => 10],
            'ws-2' => ['protocol' => 'websocket', 'bytes_sent' => 2048, 'messages_sent' => 20],
            'sse-1' => ['protocol' => 'sse', 'bytes_sent' => 512, 'events_sent' => 5],
            'wt-1' => ['protocol' => 'webtransport', 'bytes_sent' => 4096, 'datagrams_sent' => 15]
        ];
        
        foreach ($connections as $connectionId => $data) {
            $this->assertTrue($connectionManager->addConnection($connectionId, $data));
        }
        
        $stats = $connectionManager->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_connections', $stats);
        $this->assertArrayHasKey('connections_by_protocol', $stats);
        
        $this->assertSame(4, $stats['total_connections']);
        $this->assertSame(2, $stats['connections_by_protocol']['websocket']);
        $this->assertSame(1, $stats['connections_by_protocol']['sse']);
        $this->assertSame(1, $stats['connections_by_protocol']['webtransport']);
        
        // Test aggregated statistics if available
        if (isset($stats['total_bytes_sent'])) {
            $expectedTotalBytes = 1024 + 2048 + 512 + 4096;
            $this->assertSame($expectedTotalBytes, $stats['total_bytes_sent']);
        }
    }
}