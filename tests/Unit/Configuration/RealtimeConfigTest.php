<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Tests\Unit\Configuration;

use HighPerApp\HighPer\Realtime\Configuration\RealtimeConfig;
use PHPUnit\Framework\TestCase;

class RealtimeConfigTest extends TestCase
{
    private RealtimeConfig $config;
    
    protected function setUp(): void
    {
        $this->config = new RealtimeConfig();
    }
    
    protected function tearDown(): void
    {
        // Reset environment variables
        unset($_ENV['REALTIME_ENABLED']);
        unset($_ENV['REALTIME_AUTO_START']);
        unset($_ENV['REALTIME_HOST']);
        unset($_ENV['REALTIME_PORT']);
    }
    
    public function testDefaultConfiguration(): void
    {
        $this->assertFalse($this->config->isEnabled());
        $this->assertTrue($this->config->shouldAutoStart());
        $this->assertTrue($this->config->shouldAutoRegisterRoutes());
    }
    
    public function testEnvironmentOverrides(): void
    {
        $_ENV['REALTIME_ENABLED'] = 'true';
        $_ENV['REALTIME_AUTO_START'] = 'false';
        $_ENV['REALTIME_HOST'] = '127.0.0.1';
        $_ENV['REALTIME_PORT'] = '9000';
        
        $config = new RealtimeConfig();
        
        $this->assertTrue($config->isEnabled());
        $this->assertFalse($config->shouldAutoStart());
        $this->assertSame('127.0.0.1', $config->getHost());
        $this->assertSame(9000, $config->getPort());
    }
    
    public function testCustomConfiguration(): void
    {
        $customConfig = [
            'enabled' => true,
            'host' => '192.168.1.1',
            'port' => 8081,
            'max_connections' => 5000
        ];
        
        $config = new RealtimeConfig($customConfig);
        
        $this->assertTrue($config->isEnabled());
        $this->assertSame('192.168.1.1', $config->getHost());
        $this->assertSame(8081, $config->getPort());
        $this->assertSame(5000, $config->getMaxConnections());
    }
    
    public function testProtocolConfiguration(): void
    {
        $config = new RealtimeConfig([
            'protocols' => [
                'websocket' => ['enabled' => true, 'path' => '/custom-ws'],
                'sse' => ['enabled' => false],
                'webtransport' => ['enabled' => true, 'path' => '/wt']
            ]
        ]);
        
        $this->assertTrue($config->isProtocolEnabled('websocket'));
        $this->assertFalse($config->isProtocolEnabled('sse'));
        $this->assertTrue($config->isProtocolEnabled('webtransport'));
        $this->assertSame('/custom-ws', $config->getProtocolPath('websocket'));
        $this->assertSame('/wt', $config->getProtocolPath('webtransport'));
    }
    
    public function testSecurityConfiguration(): void
    {
        $config = new RealtimeConfig([
            'security' => [
                'auth_enabled' => true,
                'rate_limit_enabled' => true,
                'cors_enabled' => false
            ]
        ]);
        
        $this->assertTrue($config->isAuthEnabled());
        $this->assertTrue($config->isRateLimitEnabled());
        $this->assertFalse($config->isCorsEnabled());
    }
    
    public function testBroadcastConfiguration(): void
    {
        $config = new RealtimeConfig([
            'broadcast' => [
                'driver' => 'redis',
                'redis' => [
                    'host' => 'redis.example.com',
                    'port' => 6380,
                    'database' => 2
                ]
            ]
        ]);
        
        $this->assertSame('redis', $config->getBroadcastDriver());
        $this->assertSame('redis.example.com', $config->getBroadcastConfig('redis.host'));
        $this->assertSame(6380, $config->getBroadcastConfig('redis.port'));
        $this->assertSame(2, $config->getBroadcastConfig('redis.database'));
    }
    
    public function testPerformanceConfiguration(): void
    {
        $config = new RealtimeConfig([
            'performance' => [
                'memory_limit_mb' => 1024,
                'worker_processes' => 8,
                'connection_pool_size' => 200
            ]
        ]);
        
        $this->assertSame(1024, $config->getMemoryLimitMb());
        $this->assertSame(8, $config->getWorkerProcesses());
        $this->assertSame(200, $config->getConnectionPoolSize());
    }
    
    public function testConfigValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new RealtimeConfig([
            'port' => -1 // Invalid port
        ]);
    }
    
    public function testToArray(): void
    {
        $config = new RealtimeConfig(['enabled' => true]);
        $array = $config->toArray();
        
        $this->assertIsArray($array);
        $this->assertTrue($array['enabled']);
        $this->assertArrayHasKey('host', $array);
        $this->assertArrayHasKey('port', $array);
    }
}