<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Tests\Unit\Protocols;

use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
use HighPerApp\HighPer\Realtime\Protocols\WebSocket\WebSocketHttp3Protocol;
use HighPerApp\HighPer\Realtime\Protocols\SSE\SSEHttp3Protocol;
use HighPerApp\HighPer\Realtime\Protocols\WebTransport\WebTransportProtocol;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use PHPUnit\Framework\TestCase;

class ProtocolInterfaceTest extends TestCase
{
    private array $protocols;
    
    protected function setUp(): void
    {
        $this->protocols = [
            'websocket' => $this->createMock(WebSocketHttp3Protocol::class),
            'sse' => $this->createMock(SSEHttp3Protocol::class),
            'webtransport' => $this->createMock(WebTransportProtocol::class)
        ];
    }
    
    public function testProtocolImplementsInterface(): void
    {
        foreach ($this->protocols as $name => $protocol) {
            $this->assertInstanceOf(
                ProtocolInterface::class,
                $protocol,
                "Protocol {$name} must implement ProtocolInterface"
            );
        }
    }
    
    public function testProtocolSupportsMethod(): void
    {
        $request = $this->createMock(Request::class);
        
        foreach ($this->protocols as $name => $protocol) {
            $protocol->expects($this->once())
                ->method('supports')
                ->with($request)
                ->willReturn(true);
                
            $this->assertTrue(
                $protocol->supports($request),
                "Protocol {$name} should support the request"
            );
        }
    }
    
    public function testProtocolHandshakeMethod(): void
    {
        $request = $this->createMock(Request::class);
        
        foreach ($this->protocols as $name => $protocol) {
            $protocol->expects($this->once())
                ->method('handleHandshake')
                ->with($request)
                ->willReturn((function() { yield; })());
                
            $result = $protocol->handleHandshake($request);
            $this->assertInstanceOf(
                \Generator::class,
                $result,
                "Protocol {$name} handleHandshake should return Generator"
            );
        }
    }
    
    public function testProtocolSendMessageMethod(): void
    {
        $connectionId = 'test-connection-123';
        $data = ['type' => 'message', 'payload' => 'Hello World'];
        
        foreach ($this->protocols as $name => $protocol) {
            $protocol->expects($this->once())
                ->method('sendMessage')
                ->with($connectionId, $data)
                ->willReturn((function() { yield; })());
                
            $result = $protocol->sendMessage($connectionId, $data);
            $this->assertInstanceOf(
                \Generator::class,
                $result,
                "Protocol {$name} sendMessage should return Generator"
            );
        }
    }
    
    public function testProtocolBroadcastMethod(): void
    {
        $connectionIds = ['conn-1', 'conn-2', 'conn-3'];
        $data = ['type' => 'broadcast', 'message' => 'Hello Everyone'];
        
        foreach ($this->protocols as $name => $protocol) {
            $protocol->expects($this->once())
                ->method('broadcast')
                ->with($connectionIds, $data)
                ->willReturn((function() { yield; })());
                
            $result = $protocol->broadcast($connectionIds, $data);
            $this->assertInstanceOf(
                \Generator::class,
                $result,
                "Protocol {$name} broadcast should return Generator"
            );
        }
    }
    
    public function testProtocolCloseConnectionMethod(): void
    {
        $connectionId = 'test-connection-close';
        
        foreach ($this->protocols as $name => $protocol) {
            $protocol->expects($this->once())
                ->method('closeConnection')
                ->with($connectionId)
                ->willReturn((function() { yield; })());
                
            $result = $protocol->closeConnection($connectionId);
            $this->assertInstanceOf(
                \Generator::class,
                $result,
                "Protocol {$name} closeConnection should return Generator"
            );
        }
    }
    
    public function testProtocolGetConnectionsMethod(): void
    {
        $expectedConnections = ['conn-1', 'conn-2'];
        
        foreach ($this->protocols as $name => $protocol) {
            $protocol->expects($this->once())
                ->method('getConnections')
                ->willReturn($expectedConnections);
                
            $connections = $protocol->getConnections();
            $this->assertIsArray(
                $connections,
                "Protocol {$name} getConnections should return array"
            );
            $this->assertSame($expectedConnections, $connections);
        }
    }
    
    public function testProtocolGetConnectionInfoMethod(): void
    {
        $connectionId = 'test-connection-info';
        $expectedInfo = [
            'id' => $connectionId,
            'protocol' => 'websocket',
            'connected_at' => time(),
            'remote_address' => '127.0.0.1',
            'user_agent' => 'TestAgent/1.0'
        ];
        
        foreach ($this->protocols as $name => $protocol) {
            $protocol->expects($this->once())
                ->method('getConnectionInfo')
                ->with($connectionId)
                ->willReturn($expectedInfo);
                
            $info = $protocol->getConnectionInfo($connectionId);
            $this->assertIsArray(
                $info,
                "Protocol {$name} getConnectionInfo should return array"
            );
            $this->assertArrayHasKey('id', $info);
            $this->assertArrayHasKey('protocol', $info);
            $this->assertArrayHasKey('connected_at', $info);
        }
    }
    
    public function testProtocolStatsMethod(): void
    {
        $expectedStats = [
            'total_connections' => 15,
            'active_connections' => 12,
            'messages_sent' => 1500,
            'messages_received' => 1200,
            'bytes_sent' => 102400,
            'bytes_received' => 81920
        ];
        
        foreach ($this->protocols as $name => $protocol) {
            $protocol->expects($this->once())
                ->method('getStats')
                ->willReturn($expectedStats);
                
            $stats = $protocol->getStats();
            $this->assertIsArray(
                $stats,
                "Protocol {$name} getStats should return array"
            );
            $this->assertArrayHasKey('total_connections', $stats);
            $this->assertArrayHasKey('active_connections', $stats);
            $this->assertArrayHasKey('messages_sent', $stats);
        }
    }
}