<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Tests\Unit\ConnectionPool;

use HighPerApp\HighPer\Realtime\ConnectionPool\ConnectionPoolManager;
use HighPerApp\HighPer\Realtime\Configuration\RealtimeConfig;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ConnectionPoolManagerTest extends TestCase
{
    private ConnectionPoolManager $poolManager;
    private MockObject $config;
    
    protected function setUp(): void
    {
        $this->config = $this->createMock(RealtimeConfig::class);
        $this->config->method('getConnectionPoolSize')->willReturn(100);
        $this->config->method('getConnectionTimeout')->willReturn(300);
        $this->config->method('getMaxConnections')->willReturn(10000);
        
        $this->poolManager = new ConnectionPoolManager($this->config);
    }
    
    public function testAddConnection(): void
    {
        $connectionId = 'test-connection-1';
        $connectionData = [
            'protocol' => 'websocket',
            'remote_address' => '127.0.0.1',
            'connected_at' => time()
        ];
        
        $result = $this->poolManager->addConnection($connectionId, $connectionData);
        
        $this->assertTrue($result);
        $this->assertTrue($this->poolManager->hasConnection($connectionId));
    }
    
    public function testRemoveConnection(): void
    {
        $connectionId = 'test-connection-remove';
        $connectionData = ['protocol' => 'websocket'];
        
        $this->poolManager->addConnection($connectionId, $connectionData);
        $this->assertTrue($this->poolManager->hasConnection($connectionId));
        
        $result = $this->poolManager->removeConnection($connectionId);
        
        $this->assertTrue($result);
        $this->assertFalse($this->poolManager->hasConnection($connectionId));
    }
    
    public function testGetConnection(): void
    {
        $connectionId = 'test-connection-get';
        $connectionData = [
            'protocol' => 'sse',
            'remote_address' => '192.168.1.100',
            'user_agent' => 'TestClient/1.0'
        ];
        
        $this->poolManager->addConnection($connectionId, $connectionData);
        $retrieved = $this->poolManager->getConnection($connectionId);
        
        $this->assertIsArray($retrieved);
        $this->assertSame('sse', $retrieved['protocol']);
        $this->assertSame('192.168.1.100', $retrieved['remote_address']);
        $this->assertSame('TestClient/1.0', $retrieved['user_agent']);
    }
    
    public function testGetConnectionNotFound(): void
    {
        $result = $this->poolManager->getConnection('non-existent-connection');
        
        $this->assertNull($result);
    }
    
    public function testGetAllConnections(): void
    {
        $connections = [
            'conn-1' => ['protocol' => 'websocket'],
            'conn-2' => ['protocol' => 'sse'],
            'conn-3' => ['protocol' => 'webtransport']
        ];
        
        foreach ($connections as $id => $data) {
            $this->poolManager->addConnection($id, $data);
        }
        
        $allConnections = $this->poolManager->getAllConnections();
        
        $this->assertCount(3, $allConnections);
        $this->assertArrayHasKey('conn-1', $allConnections);
        $this->assertArrayHasKey('conn-2', $allConnections);
        $this->assertArrayHasKey('conn-3', $allConnections);
    }
    
    public function testGetConnectionsByProtocol(): void
    {
        $connections = [
            'ws-1' => ['protocol' => 'websocket'],
            'ws-2' => ['protocol' => 'websocket'],
            'sse-1' => ['protocol' => 'sse'],
            'wt-1' => ['protocol' => 'webtransport']
        ];
        
        foreach ($connections as $id => $data) {
            $this->poolManager->addConnection($id, $data);
        }
        
        $websocketConnections = $this->poolManager->getConnectionsByProtocol('websocket');
        $sseConnections = $this->poolManager->getConnectionsByProtocol('sse');
        
        $this->assertCount(2, $websocketConnections);
        $this->assertCount(1, $sseConnections);
        $this->assertArrayHasKey('ws-1', $websocketConnections);
        $this->assertArrayHasKey('ws-2', $websocketConnections);
        $this->assertArrayHasKey('sse-1', $sseConnections);
    }
    
    public function testUpdateConnectionMetadata(): void
    {
        $connectionId = 'test-connection-update';
        $initialData = [
            'protocol' => 'websocket',
            'messages_sent' => 0,
            'last_activity' => time()
        ];
        
        $this->poolManager->addConnection($connectionId, $initialData);
        
        $updateData = [
            'messages_sent' => 5,
            'last_activity' => time() + 10
        ];
        
        $result = $this->poolManager->updateConnection($connectionId, $updateData);
        $updated = $this->poolManager->getConnection($connectionId);
        
        $this->assertTrue($result);
        $this->assertSame(5, $updated['messages_sent']);
        $this->assertSame($updateData['last_activity'], $updated['last_activity']);
        $this->assertSame('websocket', $updated['protocol']); // Should remain unchanged
    }
    
    public function testConnectionCount(): void
    {
        $this->assertSame(0, $this->poolManager->getConnectionCount());
        
        $this->poolManager->addConnection('conn-1', ['protocol' => 'websocket']);
        $this->assertSame(1, $this->poolManager->getConnectionCount());
        
        $this->poolManager->addConnection('conn-2', ['protocol' => 'sse']);
        $this->assertSame(2, $this->poolManager->getConnectionCount());
        
        $this->poolManager->removeConnection('conn-1');
        $this->assertSame(1, $this->poolManager->getConnectionCount());
    }
    
    public function testMaxConnectionsLimit(): void
    {
        $this->config->method('getMaxConnections')->willReturn(2);
        $poolManager = new ConnectionPoolManager($this->config);
        
        $this->assertTrue($poolManager->addConnection('conn-1', ['protocol' => 'websocket']));
        $this->assertTrue($poolManager->addConnection('conn-2', ['protocol' => 'sse']));
        $this->assertFalse($poolManager->addConnection('conn-3', ['protocol' => 'webtransport']));
        
        $this->assertSame(2, $poolManager->getConnectionCount());
    }
    
    public function testCleanupExpiredConnections(): void
    {
        $now = time();
        $connections = [
            'active-conn' => [
                'protocol' => 'websocket',
                'last_activity' => $now - 100 // Active connection
            ],
            'expired-conn' => [
                'protocol' => 'sse',
                'last_activity' => $now - 400 // Expired connection
            ]
        ];
        
        foreach ($connections as $id => $data) {
            $this->poolManager->addConnection($id, $data);
        }
        
        $cleaned = $this->poolManager->cleanupExpiredConnections(300);
        
        $this->assertSame(1, $cleaned);
        $this->assertTrue($this->poolManager->hasConnection('active-conn'));
        $this->assertFalse($this->poolManager->hasConnection('expired-conn'));
    }
    
    public function testGetStats(): void
    {
        $connections = [
            'ws-1' => ['protocol' => 'websocket', 'bytes_sent' => 1024],
            'ws-2' => ['protocol' => 'websocket', 'bytes_sent' => 2048],
            'sse-1' => ['protocol' => 'sse', 'bytes_sent' => 512]
        ];
        
        foreach ($connections as $id => $data) {
            $this->poolManager->addConnection($id, $data);
        }
        
        $stats = $this->poolManager->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_connections', $stats);
        $this->assertArrayHasKey('connections_by_protocol', $stats);
        $this->assertArrayHasKey('pool_size', $stats);
        $this->assertArrayHasKey('max_connections', $stats);
        
        $this->assertSame(3, $stats['total_connections']);
        $this->assertSame(2, $stats['connections_by_protocol']['websocket']);
        $this->assertSame(1, $stats['connections_by_protocol']['sse']);
    }
}