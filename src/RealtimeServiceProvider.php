<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime;

use HighPerApp\HighPer\ServiceProvider\CoreServiceProvider;
use HighPerApp\HighPer\Contracts\ContainerInterface;
use HighPerApp\HighPer\Contracts\ApplicationInterface;
use HighPerApp\HighPer\Realtime\Configuration\RealtimeConfig;
use HighPerApp\HighPer\Realtime\Servers\UnifiedRealtimeServer;
use HighPerApp\HighPer\Realtime\Broadcasting\BroadcastManager;
use HighPerApp\HighPer\Realtime\Protocols\WebSocketProtocol;
use HighPerApp\HighPer\Realtime\Protocols\ServerSentEventsProtocol;
use HighPerApp\HighPer\Realtime\Protocols\WebTransportProtocol;
use Psr\Log\LoggerInterface;

class RealtimeServiceProvider extends CoreServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Get config from environment
        $config = new RealtimeConfig($_ENV['REALTIME_CONFIG'] ?? []);
        
        // Only register if real-time features are enabled
        if (!$config->isEnabled()) {
            return;
        }

        // Register configuration
        $this->singleton($container, RealtimeConfig::class, fn() => $config);

        // Register protocols based on configuration
        $this->registerProtocols($container, $config);

        // Register servers
        $this->registerServers($container, $config);

        // Register broadcasting services
        $this->registerBroadcasting($container, $config);
    }

    public function boot(ApplicationInterface $app): void
    {
        $container = $app->getContainer();
        $config = $container->get(RealtimeConfig::class);
        
        if (!$config->isEnabled()) {
            return;
        }

        // Auto-start servers based on configuration
        if ($config->shouldAutoStart()) {
            $this->startRealtimeServers($container, $config);
        }

        // Register routes if auto-routing is enabled
        if ($config->shouldAutoRegisterRoutes()) {
            $this->registerRealtimeRoutes($container, $config);
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