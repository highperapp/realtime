# HighPer Real-Time Web Library

A high-performance, pluggable real-time web library for the HighPer PHP framework, supporting WebSocket, Server-Sent Events (SSE), and WebTransport protocols.

## Features

- 🚀 **High Performance**: Built on AMPHP for maximum concurrency (C10M capability)
- 🔌 **Pluggable Architecture**: Optional installation and configuration
- 🌐 **Multi-Protocol Support**: WebSocket, SSE, WebTransport (HTTP/3 + QUIC)
- ⚙️ **Environment Driven**: Complete configuration through environment variables
- 📡 **Broadcasting**: Memory, Redis, and NATS broadcasting drivers
- 🔒 **Security**: JWT authentication, rate limiting, CORS support
- 📊 **Modular Observability**: Optional monitoring and tracing standalone libraries
- 🔍 **Zero-Config Telemetry**: Auto-discovery with graceful degradation
- 🛡️ **Enterprise Features**: Security monitoring, distributed tracing, metrics export
- 🎯 **Protocol Abstraction**: Unified API across all real-time protocols
- 🔄 **Zero-Downtime**: Connection migration support for deployments
- 📦 **HighPer Integration**: Native integration with HighPer framework

## Installation

```bash
# Install the real-time extension package
composer require highperapp/realtime

# Copy environment configuration
cp vendor/highperapp/realtime/.env.example .env
```

## Quick Start

### 1. Basic Setup

```bash
# Enable real-time features
echo "REALTIME_ENABLED=true" >> .env
echo "WEBSOCKET_ENABLED=true" >> .env
echo "SSE_ENABLED=true" >> .env

# Optional: Enable observability libraries (install separately)
echo "TELEMETRY_ENABLED=true" >> .env  # Enables both if available
echo "MONITORING_ENABLED=true" >> .env # Enable monitoring only
echo "TRACING_ENABLED=true" >> .env    # Enable tracing only
```

### 2. Auto-Integration

The library automatically integrates with your HighPer application when real-time environment variables are detected:

```php
<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';

use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Realtime\RealtimeServiceProvider;

$container = new Container();
$router = new Router($container);
$app = new Application($container, $router);

// Real-time features are auto-registered when environment variables are set
// through the HighPer framework's service provider auto-discovery

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

Create a `.env` file or add to your existing `.env` (see `.env.example` for all options):

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

## Monitoring and Observability

### Modular Observability Architecture

HighPer Real-time integrates with **separate standalone libraries** for flexible observability:

- `highperapp/monitoring` - Performance metrics, security monitoring, health checks
- `highperapp/tracing` - Distributed tracing, OpenTelemetry integration

### Installation Options

#### Option 1: Monitoring Only (Nano Apps)
```bash
composer require highperapp/monitoring
echo "MONITORING_ENABLED=true" >> .env
```
**Benefits:** Metrics, security, health checks (~5MB overhead)

#### Option 2: Tracing Only (Debug/Development)
```bash
composer require highperapp/tracing
echo "TRACING_ENABLED=true" >> .env
```
**Benefits:** Distributed tracing, correlation IDs (~8MB overhead)

#### Option 3: Full Observability (Enterprise)
```bash
composer require highperapp/monitoring highperapp/tracing
echo "TELEMETRY_ENABLED=true" >> .env
```
**Benefits:** Complete observability stack (~12MB overhead)

### Zero-Configuration Features

**Automatic when libraries are installed:**
- ✅ **Real-time Protocol Auto-Instrumentation**: WebSocket, SSE, HTTP/3, WebTransport
- ✅ **Graceful Degradation**: Missing libraries don't break functionality
- ✅ **Smart Sampling**: Environment-based optimization
- ✅ **Health Check Endpoints**: Kubernetes-ready probes
- ✅ **Export Integration**: Prometheus metrics, Jaeger/Zipkin tracing

### Health Check Endpoints

| Endpoint | Purpose | Description |
|----------|---------|-------------|
| `/health/telemetry` | Overall health | Complete telemetry system status |
| `/health/monitoring` | Monitoring health | Metrics collection status |
| `/health/tracing` | Tracing health | Span processing status |
| `/health/protocols` | Protocol health | Real-time protocol status |
| `/health/ready` | Readiness probe | Kubernetes readiness check |
| `/health/live` | Liveness probe | Kubernetes liveness check |
| `/metrics` | Prometheus metrics | Metrics in Prometheus format |

### Sample Health Check Response

```bash
curl http://localhost:8080/health/telemetry
```

```json
{
  "status": "healthy",
  "timestamp": 1703097600,
  "version": "3.0.0",
  "environment": "production",
  "monitoring": {
    "status": "active",
    "events_tracked": 15847,
    "performance_monitor": {"status": "healthy"},
    "metrics_collector": {"status": "healthy", "buffer_utilization": 23.5},
    "security_monitor": {"status": "healthy", "threats_detected": 0}
  },
  "tracing": {
    "status": "active",
    "spans_exported": 3421,
    "export_success_rate": 99.8,
    "active_spans": 12
  },
  "protocols": {
    "websocket": {"status": "healthy", "connections": 1250},
    "sse": {"status": "healthy", "connections": 350},
    "http3": {"status": "healthy", "active_streams": 89}
  },
  "response_time_ms": 2.3
}
```

### Automatic Protocol Instrumentation

Your existing code gets automatic monitoring without any changes:

```php
<?php

// Before: Just your business logic
$app->websocket('/chat', function($connection, $message) {
    $connection->send(['echo' => $message->getPayload()]);
    $connection->broadcast(['announcement' => 'New message']);
});

// After telemetry enabled: Same code, but now you get:
// ✅ websocket.connections.total metrics
// ✅ websocket.messages.{total,size} tracking  
// ✅ Distributed tracing spans
// ✅ Security monitoring for threats
// ✅ Performance optimization insights
// ✅ Error tracking and alerting
```

### Prometheus Metrics Example

```bash
curl http://localhost:8080/metrics
```

```
# TYPE websocket_connections_total counter
websocket_connections_total 1250

# TYPE websocket_messages_total counter  
websocket_messages_total{type="chat"} 8934
websocket_messages_total{type="notification"} 432

# TYPE http3_request_duration_seconds histogram
http3_request_duration_seconds_bucket{le="0.1"} 1205
http3_request_duration_seconds_bucket{le="0.5"} 1890
http3_request_duration_seconds_bucket{le="1.0"} 1950
http3_request_duration_seconds_bucket{le="+Inf"} 2000

# TYPE sse_events_sent_total counter
sse_events_sent_total{channel="notifications"} 5672
```

### Advanced Configuration

Configure observability libraries independently:

```bash
# Monitoring library configuration
MONITORING_SAMPLING_RATE=0.1      # 10% sampling
MONITORING_SECURITY_ENABLED=true  # Security monitoring
PROMETHEUS_ENABLED=true           # Metrics export

# Tracing library configuration
TRACING_SAMPLING_RATE=0.05        # 5% sampling
SERVICE_NAME=my-highper-app       # Service identification
TRACING_JAEGER_ENABLED=true       # Jaeger export

# Real-time specific
REALTIME_AUTO_INSTRUMENT=true     # Auto-instrument protocols
```

### Security Monitoring

Automatic threat detection for:
- SQL injection attempts
- XSS attacks  
- Brute force patterns
- Rate limiting violations
- Geographic anomalies
- Suspicious user agents

```json
{
  "security_event": {
    "type": "sql_injection",
    "severity": "high", 
    "client_ip": "192.168.1.100",
    "blocked": true,
    "timestamp": 1703097600
  }
}
```

### Custom Metrics and Tracing (Optional)

Add business-specific observability when libraries are available:

```php
<?php

// Check if monitoring library is available
if (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor')) {
    use HighPerApp\HighPer\Monitoring\Facades\Monitor;
    
    // Custom business metrics
    Monitor::increment('orders.completed');
    Monitor::gauge('inventory.level', $currentStock);
    Monitor::timing('payment.processing', $duration);
    
    // Security events
    Monitor::security('failed_login', [
        'user_id' => $userId,
        'ip' => $clientIp,
        'severity' => 'medium'
    ]);
}

// Check if tracing library is available
if (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')) {
    use HighPerApp\HighPer\Tracing\Facades\Trace;
    
    // Custom tracing spans
    Trace::span('user.registration', function() use ($userData) {
        return $this->registerUser($userData);
    });
}
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

## Testing

The library includes comprehensive tests covering unit, integration, performance, concurrency, and reliability testing.

### Test Structure

```
tests/
├── Unit/                    # Unit tests for core components
│   ├── Configuration/       # Configuration testing
│   ├── Protocols/          # Protocol interface testing
│   └── ConnectionPool/     # Connection management testing
├── Integration/            # Integration tests
├── Performance/           # Performance benchmarking tests
├── Concurrency/          # Concurrency and race condition tests
├── Reliability/          # Reliability and fault tolerance tests
└── phpunit.xml          # PHPUnit configuration
```

### Running Tests

```bash
# Install development dependencies
composer install

# Run unit and integration tests
composer test

# Run specific test suites
composer test -- --group unit
composer test -- --group integration

# Run performance tests (tagged separately)
composer test -- --group performance

# Run concurrency tests
composer test -- --group concurrency

# Run reliability tests
composer test -- --group reliability

# Run all tests including performance/concurrency/reliability
composer test -- --group unit,integration,performance,concurrency,reliability

# Generate coverage report
composer test -- --coverage-html coverage/
```

### Test Environment Configuration

Tests use the following environment configuration (see `phpunit.xml`):

```bash
APP_ENV=testing
REALTIME_ENABLED=true
REALTIME_HOST=127.0.0.1
REALTIME_PORT=8080
WEBSOCKET_ENABLED=true
SSE_ENABLED=true
WEBTRANSPORT_ENABLED=true
REALTIME_MAX_CONNECTIONS=1000
REALTIME_CONNECTION_TIMEOUT=30
```

### Test Categories

#### Unit Tests
- **Configuration Testing**: Validates environment variable parsing, default values, and config validation
- **Protocol Interface Testing**: Tests protocol implementation contracts and method signatures
- **Connection Pool Testing**: Tests connection management, limits, cleanup, and statistics

#### Integration Tests
- **Service Provider Integration**: Tests automatic service registration and bootstrapping
- **Multi-Protocol Support**: Tests WebSocket, SSE, and WebTransport integration
- **Broadcasting**: Tests cross-protocol message broadcasting
- **Connection Lifecycle**: Tests complete connection establishment, management, and cleanup

#### Performance Tests
- **Message Processing Latency**: Measures message handling performance (target: <100ms average)
- **Throughput Testing**: Tests message processing rate (target: >1000 msg/sec)
- **Memory Usage**: Monitors memory consumption under load (target: <50MB for 1000 connections)
- **Broadcast Performance**: Tests message delivery to multiple connections
- **Connection Establishment**: Measures connection setup time
- **Concurrent Handling**: Tests performance under concurrent load

#### Concurrency Tests
- **Race Condition Protection**: Tests data integrity under concurrent access
- **Deadlock Prevention**: Validates resource locking mechanisms
- **Resource Contention**: Tests behavior under high resource contention
- **Concurrent Connections**: Tests handling of simultaneous connection attempts
- **Concurrent Broadcasting**: Tests concurrent message broadcasting

#### Reliability Tests
- **Connection Recovery**: Tests automatic reconnection and recovery mechanisms
- **Heartbeat Mechanism**: Validates connection health monitoring
- **Graceful Degradation**: Tests system behavior under various failure conditions
- **Load Balancing**: Tests failover and load distribution reliability
- **Data Integrity**: Validates data consistency under failure scenarios

### Performance Benchmarks

The library targets the following performance benchmarks:

| Metric | Target | Test Coverage |
|--------|---------|---------------|
| Message Latency (avg) | <100ms | ✅ Performance Tests |
| Message Latency (P95) | <200ms | ✅ Performance Tests |
| Throughput | >1000 msg/sec | ✅ Performance Tests |
| Memory Usage | <50KB per connection | ✅ Performance Tests |
| Connection Rate | >100 conn/sec | ✅ Performance Tests |
| Broadcast Speed | <500ms for 1000 connections | ✅ Performance Tests |
| Recovery Time | <5s after failure | ✅ Reliability Tests |
| Data Integrity | >99% under failures | ✅ Reliability Tests |

### Continuous Integration

The test suite is designed for CI/CD integration:

```yaml
# GitHub Actions example
- name: Run Tests
  run: |
    composer install
    composer test
    composer test -- --group performance --stop-on-failure
    
- name: Generate Coverage
  run: composer test -- --coverage-clover coverage.xml
  
- name: Upload Coverage
  uses: codecov/codecov-action@v3
  with:
    file: coverage.xml
```

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. **Add comprehensive tests** (required for all changes)
5. Run the test suite: `composer test`
6. Run performance tests: `composer test -- --group performance`
7. Ensure all tests pass and coverage is maintained
8. Commit your changes (`git commit -m 'Add amazing feature'`)
9. Push to the branch (`git push origin feature/amazing-feature`)
10. Submit a pull request

### Testing Requirements for Contributors

- **Unit tests required** for all new functionality
- **Integration tests required** for new protocols or major features
- **Performance tests required** for performance-critical changes
- **Minimum 90% code coverage** for new code
- **All existing tests must pass** before merge
- **Performance benchmarks must not regress** by more than 10%

### Development Environment Setup

```bash
# Clone repository
git clone https://github.com/highperapp/realtime.git
cd realtime

# Install dependencies
composer install

# Copy test environment
cp .env.example .env.testing

# Run tests to verify setup
composer test

# Run performance tests
composer test -- --group performance

# Check code quality
composer analyse  # PHPStan analysis
composer format   # PHP-CS-Fixer formatting
```

## License

MIT License - see LICENSE file for details.

## Support

- Documentation: [HighPer Framework Documentation](https://docs.highperapp.com)
- Issues: [GitHub Issues](https://github.com/highperapp/realtime/issues)
- Community: [HighPer Community](https://github.com/highperapp/highperapp-framework/discussions)
- Performance Issues: Use performance test results when reporting
- Security Issues: Please report privately to security@highperapp.com