<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Configuration;

class RealtimeConfig
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->loadFromEnvironment();
    }

    public function isEnabled(): bool
    {
        return (bool)$this->config['enabled'];
    }

    public function shouldAutoStart(): bool
    {
        return (bool)$this->config['auto_start'];
    }

    public function shouldAutoRegisterRoutes(): bool
    {
        return (bool)$this->config['auto_register_routes'];
    }

    public function getEnabledProtocols(): array
    {
        return array_filter($this->config['protocols'], fn($protocol) => $protocol['enabled']);
    }

    public function isProtocolEnabled(string $protocol): bool
    {
        return $this->config['protocols'][$protocol]['enabled'] ?? false;
    }

    public function getWebSocketConfig(): array
    {
        return $this->config['protocols']['websocket'];
    }

    public function getSSEConfig(): array
    {
        return $this->config['protocols']['sse'];
    }

    public function getWebTransportConfig(): array
    {
        return $this->config['protocols']['webtransport'];
    }

    public function getHttp3Config(): array
    {
        return $this->config['protocols']['http3'];
    }

    public function getBroadcastingConfig(): array
    {
        return $this->config['broadcasting'];
    }

    public function getServerConfig(): array
    {
        return $this->config['server'];
    }

    private function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
            'auto_start' => true,
            'auto_register_routes' => true,
            
            'server' => [
                'host' => '0.0.0.0',
                'port' => 8080,
                'max_connections' => 10000,
                'connection_timeout' => 300,
                'heartbeat_interval' => 30,
                'buffer_size' => 8192,
            ],

            'protocols' => [
                'websocket' => [
                    'enabled' => false,
                    'path' => '/ws',
                    'subprotocols' => [],
                    'max_frame_size' => 2097152, // 2MB
                    'max_message_size' => 10485760, // 10MB
                    'compression' => true,
                    'validate_utf8' => true,
                    'heartbeat' => [
                        'enabled' => true,
                        'interval' => 30,
                        'timeout' => 10
                    ]
                ],
                
                'sse' => [
                    'enabled' => false,
                    'path' => '/sse',
                    'retry_interval' => 3000,
                    'keep_alive_interval' => 25,
                    'max_event_size' => 65536, // 64KB
                    'cors' => [
                        'enabled' => true,
                        'origins' => ['*'],
                        'headers' => ['Cache-Control', 'Content-Type']
                    ]
                ],
                
                'webtransport' => [
                    'enabled' => false,
                    'path' => '/wt',
                    'max_streams' => 1000,
                    'max_datagram_size' => 1200,
                    'reliability' => 'reliable_ordered', // reliable_ordered, reliable_unordered, unreliable_ordered, unreliable_unordered
                    'congestion_control' => 'bbr2',
                    'enable_0rtt' => true
                ],
                
                'http3' => [
                    'enabled' => false,
                    'port' => 443,
                    'enable_0rtt' => true,
                    'enable_early_data' => true,
                    'max_connections' => 10000,
                    'connection_timeout' => 300,
                    'quic_version' => 'draft-34',
                    'alt_svc_max_age' => 3600,
                    'enable_protocol_negotiation' => true,
                    'fallback_protocols' => ['h2', 'http/1.1'],
                    'prefer_http3' => true,
                    'min_http3_score' => 150,
                    'adaptive_selection' => true
                ]
            ],

            'broadcasting' => [
                'default' => 'memory',
                'drivers' => [
                    'memory' => [
                        'max_channels' => 10000,
                        'max_subscribers_per_channel' => 1000
                    ],
                    'redis' => [
                        'host' => 'localhost',
                        'port' => 6379,
                        'database' => 0,
                        'prefix' => 'highper:realtime:',
                        'cluster' => false
                    ],
                    'nats' => [
                        'servers' => ['nats://localhost:4222'],
                        'subject_prefix' => 'highper.realtime'
                    ]
                ]
            ],

            'security' => [
                'authentication' => [
                    'enabled' => false,
                    'driver' => 'jwt', // jwt, session, custom
                    'jwt' => [
                        'secret' => null,
                        'algorithm' => 'HS256',
                        'verify_signature' => true,
                        'verify_expiration' => true
                    ]
                ],
                'rate_limiting' => [
                    'enabled' => false,
                    'connections_per_ip' => 10,
                    'messages_per_minute' => 60,
                    'burst_limit' => 10
                ],
                'cors' => [
                    'enabled' => true,
                    'allowed_origins' => ['*'],
                    'allowed_methods' => ['GET', 'POST'],
                    'allowed_headers' => ['*']
                ]
            ],

            'monitoring' => [
                'enabled' => true,
                'metrics' => [
                    'connections' => true,
                    'messages' => true,
                    'bandwidth' => true,
                    'errors' => true
                ],
                'health_check' => [
                    'enabled' => true,
                    'path' => '/realtime/health',
                    'include_metrics' => true
                ]
            ]
        ];
    }

    private function loadFromEnvironment(): void
    {
        // Global real-time settings
        if (isset($_ENV['REALTIME_ENABLED'])) {
            $this->config['enabled'] = filter_var($_ENV['REALTIME_ENABLED'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($_ENV['REALTIME_AUTO_START'])) {
            $this->config['auto_start'] = filter_var($_ENV['REALTIME_AUTO_START'], FILTER_VALIDATE_BOOLEAN);
        }

        // Server configuration
        if (isset($_ENV['REALTIME_HOST'])) {
            $this->config['server']['host'] = $_ENV['REALTIME_HOST'];
        }

        if (isset($_ENV['REALTIME_PORT'])) {
            $this->config['server']['port'] = (int)$_ENV['REALTIME_PORT'];
        }

        if (isset($_ENV['REALTIME_MAX_CONNECTIONS'])) {
            $this->config['server']['max_connections'] = (int)$_ENV['REALTIME_MAX_CONNECTIONS'];
        }

        // WebSocket configuration
        if (isset($_ENV['WEBSOCKET_ENABLED'])) {
            $this->config['protocols']['websocket']['enabled'] = filter_var($_ENV['WEBSOCKET_ENABLED'], FILTER_VALIDATE_BOOLEAN);
            $this->config['enabled'] = $this->config['enabled'] || $this->config['protocols']['websocket']['enabled'];
        }

        if (isset($_ENV['WEBSOCKET_PATH'])) {
            $this->config['protocols']['websocket']['path'] = $_ENV['WEBSOCKET_PATH'];
        }

        if (isset($_ENV['WEBSOCKET_MAX_FRAME_SIZE'])) {
            $this->config['protocols']['websocket']['max_frame_size'] = (int)$_ENV['WEBSOCKET_MAX_FRAME_SIZE'];
        }

        // SSE configuration
        if (isset($_ENV['SSE_ENABLED'])) {
            $this->config['protocols']['sse']['enabled'] = filter_var($_ENV['SSE_ENABLED'], FILTER_VALIDATE_BOOLEAN);
            $this->config['enabled'] = $this->config['enabled'] || $this->config['protocols']['sse']['enabled'];
        }

        if (isset($_ENV['SSE_PATH'])) {
            $this->config['protocols']['sse']['path'] = $_ENV['SSE_PATH'];
        }

        if (isset($_ENV['SSE_RETRY_INTERVAL'])) {
            $this->config['protocols']['sse']['retry_interval'] = (int)$_ENV['SSE_RETRY_INTERVAL'];
        }

        // WebTransport configuration
        if (isset($_ENV['WEBTRANSPORT_ENABLED'])) {
            $this->config['protocols']['webtransport']['enabled'] = filter_var($_ENV['WEBTRANSPORT_ENABLED'], FILTER_VALIDATE_BOOLEAN);
            $this->config['enabled'] = $this->config['enabled'] || $this->config['protocols']['webtransport']['enabled'];
        }

        if (isset($_ENV['WEBTRANSPORT_PATH'])) {
            $this->config['protocols']['webtransport']['path'] = $_ENV['WEBTRANSPORT_PATH'];
        }

        if (isset($_ENV['WEBTRANSPORT_MAX_STREAMS'])) {
            $this->config['protocols']['webtransport']['max_streams'] = (int)$_ENV['WEBTRANSPORT_MAX_STREAMS'];
        }

        // HTTP/3 configuration
        if (isset($_ENV['HTTP3_ENABLED'])) {
            $this->config['protocols']['http3']['enabled'] = filter_var($_ENV['HTTP3_ENABLED'], FILTER_VALIDATE_BOOLEAN);
            $this->config['enabled'] = $this->config['enabled'] || $this->config['protocols']['http3']['enabled'];
        }

        if (isset($_ENV['HTTP3_PORT'])) {
            $this->config['protocols']['http3']['port'] = (int)$_ENV['HTTP3_PORT'];
        }

        if (isset($_ENV['HTTP3_ENABLE_0RTT'])) {
            $this->config['protocols']['http3']['enable_0rtt'] = filter_var($_ENV['HTTP3_ENABLE_0RTT'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($_ENV['HTTP3_QUIC_VERSION'])) {
            $this->config['protocols']['http3']['quic_version'] = $_ENV['HTTP3_QUIC_VERSION'];
        }

        if (isset($_ENV['HTTP3_MAX_CONNECTIONS'])) {
            $this->config['protocols']['http3']['max_connections'] = (int)$_ENV['HTTP3_MAX_CONNECTIONS'];
        }

        // Broadcasting configuration
        if (isset($_ENV['REALTIME_BROADCAST_DRIVER'])) {
            $this->config['broadcasting']['default'] = $_ENV['REALTIME_BROADCAST_DRIVER'];
        }

        if (isset($_ENV['REALTIME_REDIS_HOST'])) {
            $this->config['broadcasting']['drivers']['redis']['host'] = $_ENV['REALTIME_REDIS_HOST'];
        }

        if (isset($_ENV['REALTIME_REDIS_PORT'])) {
            $this->config['broadcasting']['drivers']['redis']['port'] = (int)$_ENV['REALTIME_REDIS_PORT'];
        }

        // Security configuration
        if (isset($_ENV['REALTIME_AUTH_ENABLED'])) {
            $this->config['security']['authentication']['enabled'] = filter_var($_ENV['REALTIME_AUTH_ENABLED'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($_ENV['REALTIME_JWT_SECRET'])) {
            $this->config['security']['authentication']['jwt']['secret'] = $_ENV['REALTIME_JWT_SECRET'];
        }

        if (isset($_ENV['REALTIME_RATE_LIMIT_ENABLED'])) {
            $this->config['security']['rate_limiting']['enabled'] = filter_var($_ENV['REALTIME_RATE_LIMIT_ENABLED'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($_ENV['REALTIME_RATE_LIMIT_CONNECTIONS'])) {
            $this->config['security']['rate_limiting']['connections_per_ip'] = (int)$_ENV['REALTIME_RATE_LIMIT_CONNECTIONS'];
        }
    }

    public function toArray(): array
    {
        return $this->config;
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }
}