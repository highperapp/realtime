<?php

declare(strict_types=1);

namespace EaseAppPHP\HighPer\Realtime;

use EaseAppPHP\HighPer\Framework\Container\Container;
use EaseAppPHP\HighPer\Framework\ServiceProvider\ServiceProviderInterface;
use EaseAppPHP\HighPer\Realtime\Configuration\RealtimeConfig;
use EaseAppPHP\HighPer\Realtime\Servers\UnifiedRealtimeServer;
use EaseAppPHP\HighPer\Realtime\Broadcasting\BroadcastManager;
use EaseAppPHP\HighPer\Realtime\Protocols\WebSocketProtocol;
use EaseAppPHP\HighPer\Realtime\Protocols\ServerSentEventsProtocol;
use EaseAppPHP\HighPer\Realtime\Protocols\WebTransportProtocol;
use Psr\Log\LoggerInterface;

class RealtimeServiceProvider implements ServiceProviderInterface
{
    private Container $container;
    private RealtimeConfig $config;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->config = new RealtimeConfig($container->get('config')['realtime'] ?? []);
    }

    public function register(): void
    {
        // Only register if real-time features are enabled
        if (!$this->config->isEnabled()) {
            return;
        }

        // Register configuration
        $this->container->bind(RealtimeConfig::class, fn() => $this->config);

        // Register protocols based on configuration
        $this->registerProtocols();

        // Register servers
        $this->registerServers();

        // Register broadcasting services
        $this->registerBroadcasting();

        // Register middleware if needed
        $this->registerMiddleware();
    }

    public function boot(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // Auto-start servers based on configuration
        if ($this->config->shouldAutoStart()) {
            $this->startRealtimeServers();
        }

        // Register routes if auto-routing is enabled
        if ($this->config->shouldAutoRegisterRoutes()) {
            $this->registerRealtimeRoutes();
        }
    }

    private function registerProtocols(): void
    {
        $enabledProtocols = $this->config->getEnabledProtocols();

        if (in_array('websocket', $enabledProtocols)) {
            $this->container->bind(WebSocketProtocol::class, function() {
                return new WebSocketProtocol(
                    $this->container->get(LoggerInterface::class),
                    $this->config->getWebSocketConfig()
                );
            });
        }

        if (in_array('sse', $enabledProtocols)) {
            $this->container->bind(ServerSentEventsProtocol::class, function() {
                return new ServerSentEventsProtocol(
                    $this->container->get(LoggerInterface::class),
                    $this->config->getSSEConfig()
                );
            });
        }

        if (in_array('webtransport', $enabledProtocols)) {
            $this->container->bind(WebTransportProtocol::class, function() {
                return new WebTransportProtocol(
                    $this->container->get(LoggerInterface::class),
                    $this->config->getWebTransportConfig()
                );
            });
        }

        if (in_array('http3', $enabledProtocols)) {
            $this->container->bind(Http3Server::class, function() {
                return new Http3Server(
                    $this->container->get(LoggerInterface::class),
                    $this->config->getHttp3Config()
                );
            });
        }
    }

    private function registerServers(): void
    {
        $this->container->bind(UnifiedRealtimeServer::class, function() {
            $protocols = [];
            
            foreach ($this->config->getEnabledProtocols() as $protocolName) {
                $protocolClass = $this->getProtocolClass($protocolName);
                if ($this->container->has($protocolClass)) {
                    $protocols[$protocolName] = $this->container->get($protocolClass);
                }
            }

            return new UnifiedRealtimeServer(
                $protocols,
                $this->container->get(LoggerInterface::class),
                $this->config
            );
        });
    }

    private function registerBroadcasting(): void
    {
        $this->container->bind(BroadcastManager::class, function() {
            return new BroadcastManager(
                $this->container->get(LoggerInterface::class),
                $this->config->getBroadcastingConfig()
            );
        });
    }

    private function registerMiddleware(): void
    {
        // Register real-time specific middleware if needed
        // This could include authentication, rate limiting, etc.
    }

    private function startRealtimeServers(): void
    {
        $server = $this->container->get(UnifiedRealtimeServer::class);
        
        // Start in background coroutine
        \Amp\async(function() use ($server) {
            try {
                yield $server->start();
            } catch (\Throwable $e) {
                $logger = $this->container->get(LoggerInterface::class);
                $logger->error('Failed to start real-time server', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        });
    }

    private function registerRealtimeRoutes(): void
    {
        $router = $this->container->get('router');
        
        // Auto-register common real-time endpoints
        if ($this->config->isProtocolEnabled('websocket')) {
            $router->get('/ws', [UnifiedRealtimeServer::class, 'handleWebSocket']);
        }
        
        if ($this->config->isProtocolEnabled('sse')) {
            $router->get('/sse', [UnifiedRealtimeServer::class, 'handleSSE']);
        }
        
        if ($this->config->isProtocolEnabled('webtransport')) {
            $router->get('/wt', [UnifiedRealtimeServer::class, 'handleWebTransport']);
        }
        
        if ($this->config->isProtocolEnabled('http3')) {
            // HTTP/3 uses automatic protocol negotiation
            $router->any('/{path:.*}', [Http3Server::class, 'handleRequest'])
                ->middleware('http3-negotiation');
        }
    }

    private function getProtocolClass(string $protocolName): string
    {
        return match($protocolName) {
            'websocket' => WebSocketProtocol::class,
            'sse' => ServerSentEventsProtocol::class,
            'webtransport' => WebTransportProtocol::class,
            default => throw new \InvalidArgumentException("Unknown protocol: {$protocolName}")
        };
    }

    public static function shouldAutoLoad(): bool
    {
        // Auto-load if any real-time environment variables are set
        return !empty($_ENV['REALTIME_ENABLED']) || 
               !empty($_ENV['WEBSOCKET_ENABLED']) ||
               !empty($_ENV['SSE_ENABLED']) ||
               !empty($_ENV['WEBTRANSPORT_ENABLED']);
    }
}