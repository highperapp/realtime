# HighPer Real-Time Web Library

A high-performance, pluggable real-time web library for the HighPer PHP framework, supporting WebSocket, Server-Sent Events (SSE), and WebTransport protocols.

## Features

- 🚀 **High Performance**: Built on AmPHP for maximum concurrency (C10M)
- 🔌 **Pluggable Architecture**: Optional installation and configuration
- 🌐 **Multi-Protocol Support**: WebSocket, SSE, WebTransport (HTTP/3 + QUIC)
- ⚙️ **Environment Driven**: Complete configuration through environment variables
- 📡 **Broadcasting**: Memory, Redis, and NATS broadcasting drivers
- 🔒 **Security**: JWT authentication, rate limiting, CORS support
- 📊 **Monitoring**: Built-in metrics, health checks, and observability
- 🎯 **Protocol Abstraction**: Unified API across all real-time protocols

## Installation

```bash
# Install the real-time extension package
composer require highperapp/realtime

# Copy environment configuration
cp vendor/highperapp/realtime/.env.realtime.example .env.realtime
```

## Quick Start

### 1. Basic Setup

```bash
# Enable real-time features
echo "REALTIME_ENABLED=true" >> .env
echo "WEBSOCKET_ENABLED=true" >> .env
echo "SSE_ENABLED=true" >> .env
```

### 2. Auto-Integration

The library automatically integrates with your HighPer application when real-time environment variables are detected:

```php
<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';

use EaseAppPHP\HighPer\Framework\Application;
use EaseAppPHP\HighPer\Framework\ServiceProvider\RealtimeIntegration;

$container = new Container();
$router = new Router($container);
$app = new Application($container, $router);

// Real-time features are auto-detected and integrated
RealtimeIntegration::autoDetectAndIntegrate($container, $app);

$app->start();
```

### 3. Manual Integration

For more control, manually register the service provider:

```php
<?php

use HighPerApp\HighPer\Realtime\RealtimeServiceProvider;

$container = new Container();

// Configure real-time settings
$container->bind('config', function() {
    return [
        'realtime' => [
            'enabled' => true,
            'protocols' => [
                'websocket' => ['enabled' => true],
                'sse' => ['enabled' => true]
            ]
        ]
    ];
});

// Register real-time provider
$realtimeProvider = new RealtimeServiceProvider($container);
$realtimeProvider->register();
$realtimeProvider->boot();
```

## Protocol Usage

### WebSocket Example

```php
<?php

// Handle WebSocket connections
$app->websocket('/ws', function($connection, $message) {
    // Echo message back to sender
    $connection->send(['echo' => $message->getPayload()]);
    
    // Broadcast to all connections
    $connection->broadcast(['announcement' => 'New message received']);
    
    // Join/leave channels
    $connection->joinChannel('chat-room');
    $connection->broadcastToChannel('chat-room', ['user_joined' => $connection->getId()]);
});
```

### Server-Sent Events Example

```php
<?php

// SSE endpoint for live updates
$app->sse('/events', function($stream) {
    // Send initial data
    $stream->send(['type' => 'welcome', 'data' => 'Connected to live stream']);
    
    // Send periodic updates
    $timer = \Amp\repeat(1000, function() use ($stream) {
        $stream->send([
            'type' => 'update',
            'data' => ['timestamp' => time(), 'status' => 'active']
        ]);
    });
});
```

### Broadcasting Example

```php
<?php

// Broadcast to all connected clients
$broadcast = $app->broadcast();

// Broadcast to specific channel
$broadcast->toChannel('notifications')->send([
    'type' => 'alert',
    'message' => 'System maintenance in 5 minutes'
]);

// Broadcast to specific users
$broadcast->toUsers(['user1', 'user2'])->send([
    'type' => 'private_message',
    'from' => 'admin',
    'message' => 'Hello there!'
]);
```

## Configuration

### Environment Variables

Create a `.env.realtime` file or add to your main `.env`:

```bash
# Enable real-time features
REALTIME_ENABLED=true
REALTIME_AUTO_START=true

# Protocol configuration
WEBSOCKET_ENABLED=true
WEBSOCKET_PATH=/ws
SSE_ENABLED=true
SSE_PATH=/sse

# Broadcasting
REALTIME_BROADCAST_DRIVER=redis
REALTIME_REDIS_HOST=localhost
REALTIME_REDIS_PORT=6379

# Security
REALTIME_AUTH_ENABLED=true
REALTIME_JWT_SECRET=your-secret-key
REALTIME_RATE_LIMIT_ENABLED=true
```

### Programmatic Configuration

```php
<?php

$container->bind('config', function() {
    return [
        'realtime' => [
            'enabled' => true,
            'auto_start' => true,
            'server' => [
                'host' => '0.0.0.0',
                'port' => 8080,
                'max_connections' => 10000
            ],
            'protocols' => [
                'websocket' => [
                    'enabled' => true,
                    'path' => '/ws',
                    'max_frame_size' => 2097152,
                    'compression' => true
                ],
                'sse' => [
                    'enabled' => true,
                    'path' => '/sse',
                    'retry_interval' => 3000
                ]
            ],
            'broadcasting' => [
                'default' => 'redis',
                'drivers' => [
                    'redis' => [
                        'host' => 'localhost',
                        'port' => 6379
                    ]
                ]
            ]
        ]
    ];
});
```

## Use Cases

### 1. Gaming Application

```bash
# Optimized for low latency
REALTIME_ENABLED=true
WEBSOCKET_ENABLED=true
WEBTRANSPORT_ENABLED=true
WEBTRANSPORT_RELIABILITY=unreliable_unordered
REALTIME_RATE_LIMIT_MESSAGES_PER_MINUTE=300
```

### 2. Financial Trading Platform

```bash
# High security and reliability
REALTIME_ENABLED=true
WEBSOCKET_ENABLED=true
WEBTRANSPORT_RELIABILITY=reliable_ordered
REALTIME_AUTH_ENABLED=true
REALTIME_JWT_SECRET=ultra-secure-secret
REALTIME_RATE_LIMIT_ENABLED=true
```

### 3. Live Collaboration Tool

```bash
# Multi-user real-time editing
REALTIME_ENABLED=true
WEBSOCKET_ENABLED=true
SSE_ENABLED=true
REALTIME_BROADCAST_DRIVER=redis
REALTIME_AUTH_ENABLED=true
```

### 4. Live Dashboard

```bash
# Server-to-client data streaming
REALTIME_ENABLED=true
SSE_ENABLED=true
WEBSOCKET_ENABLED=true
REALTIME_MAX_CONNECTIONS=50000
```

## Monitoring and Health Checks

### Built-in Health Check

```bash
curl http://localhost:8080/realtime/health
```

Response:
```json
{
  "status": "healthy",
  "protocols": {
    "websocket": {"active_connections": 150, "status": "healthy"},
    "sse": {"active_connections": 75, "status": "healthy"}
  },
  "metrics": {
    "total_connections": 225,
    "messages_per_second": 1250,
    "memory_usage": "45MB"
  }
}
```

### Custom Metrics

```php
<?php

$metrics = $app->realtime()->getMetrics();
echo "Active connections: " . $metrics['connections']['total'];
echo "Messages/sec: " . $metrics['messages']['per_second'];
echo "Bandwidth: " . $metrics['bandwidth']['mbps'] . " Mbps";
```

## Security

### JWT Authentication

```php
<?php

// Configure JWT authentication
$app->configureRealtime([
    'security' => [
        'authentication' => [
            'enabled' => true,
            'driver' => 'jwt',
            'jwt' => [
                'secret' => 'your-secret-key',
                'verify_expiration' => true
            ]
        ]
    ]
]);

// Authenticate connections
$app->websocket('/ws', function($connection) {
    $token = $connection->getAuthToken();
    $user = JWT::decode($token, 'your-secret-key');
    
    $connection->setUser($user);
    $connection->send(['authenticated' => true, 'user' => $user->id]);
});
```

### Rate Limiting

```bash
# Environment configuration
REALTIME_RATE_LIMIT_ENABLED=true
REALTIME_RATE_LIMIT_CONNECTIONS=10
REALTIME_RATE_LIMIT_MESSAGES_PER_MINUTE=60
REALTIME_RATE_LIMIT_BURST=10
```

## Deployment

### Docker Deployment

```dockerfile
FROM php:8.3-cli

# Install real-time dependencies
RUN apt-get update && apt-get install -y libssl-dev
RUN pecl install swoole redis

COPY . /app
WORKDIR /app

# Install composer dependencies
RUN composer install --no-dev --optimize-autoloader

# Expose real-time port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/realtime/health || exit 1

CMD ["php", "public/index.php"]
```

### Kubernetes Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: highper-realtime
spec:
  replicas: 3
  selector:
    matchLabels:
      app: highper-realtime
  template:
    metadata:
      labels:
        app: highper-realtime
    spec:
      containers:
      - name: highper-realtime
        image: your-registry/highper-app:latest
        ports:
        - containerPort: 8080
        env:
        - name: REALTIME_ENABLED
          value: "true"
        - name: WEBSOCKET_ENABLED
          value: "true"
        - name: REALTIME_BROADCAST_DRIVER
          value: "redis"
        - name: REALTIME_REDIS_HOST
          value: "redis-service"
        livenessProbe:
          httpGet:
            path: /realtime/health
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /realtime/health
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 5
```

## Performance Optimization

### Connection Pooling

```bash
# Optimize for high-concurrency
REALTIME_MAX_CONNECTIONS=50000
REALTIME_CONNECTION_TIMEOUT=300
REALTIME_BUFFER_SIZE=16384
REALTIME_BACKLOG_SIZE=2000
```

### Memory Management

```bash
# Memory optimization
REALTIME_MAX_MEMORY_USAGE=1G
REALTIME_GC_INTERVAL=30
REALTIME_CLEANUP_INTERVAL=120
```

### Load Balancing

```bash
# Behind load balancer
REALTIME_BEHIND_PROXY=true
REALTIME_PROXY_PROTOCOL=true
REALTIME_TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Submit a pull request

## License

MIT License - see LICENSE file for details.

## Support

- Documentation: [https://docs.highper.dev/realtime](https://docs.highper.dev/realtime)
- Issues: [https://github.com/easeappphi/highper-realtime/issues](https://github.com/easeappphi/highper-realtime/issues)
- Community: [https://community.highper.dev](https://community.highper.dev)