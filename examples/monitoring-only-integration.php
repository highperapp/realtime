<?php

/**
 * Example: HighPer Realtime with Monitoring Library Only
 * 
 * This example demonstrates how to use the HighPer realtime library
 * with only the monitoring library installed (no tracing).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Container\Container;
use HighPerApp\HighPer\Http\Router;

// Create HighPer application
$container = new Container();
$router = new Router($container);
$app = new Application($container, $router);

// Environment configuration for monitoring-only setup
$_ENV['MONITORING_ENABLED'] = 'true';
$_ENV['TRACING_ENABLED'] = 'false';  // Explicitly disable tracing
$_ENV['REALTIME_ENABLED'] = 'true';
$_ENV['WEBSOCKET_ENABLED'] = 'true';
$_ENV['SSE_ENABLED'] = 'true';

// Application will auto-discover monitoring but skip tracing
// TelemetryServiceProvider will gracefully handle missing tracing library

// Basic HTTP endpoint
$app->get('/', function() {
    return ['message' => 'HighPer Realtime with Monitoring Only', 'timestamp' => time()];
});

// WebSocket endpoint with automatic monitoring
$app->websocket('/ws', function($connection, $message) {
    // Automatic monitoring: connection metrics, message metrics, performance tracking
    $data = json_decode($message->getPayload(), true);
    
    switch ($data['type'] ?? 'echo') {
        case 'echo':
            $connection->send(['echo' => $data['message'] ?? 'Hello']);
            break;
            
        case 'broadcast':
            $connection->broadcast(['announcement' => $data['message']]);
            break;
            
        case 'metrics':
            // If monitoring library is available, return metrics
            if (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor')) {
                $connection->send([
                    'metrics' => [
                        'connections' => 'Monitor::getValue("websocket.connections")',
                        'messages' => 'Monitor::getValue("websocket.messages")',
                        'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
                    ]
                ]);
            } else {
                $connection->send(['error' => 'Monitoring library not available']);
            }
            break;
    }
});

// SSE endpoint with automatic monitoring
$app->sse('/events', function($stream) {
    // Send welcome event
    $stream->send(['type' => 'welcome', 'message' => 'Connected to monitoring-enabled stream']);
    
    // Send periodic updates with metrics (if monitoring available)
    $timer = \Amp\repeat(2000, function() use ($stream) {
        $stream->send([
            'type' => 'system_status',
            'data' => [
                'timestamp' => time(),
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'monitoring_enabled' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
                'tracing_enabled' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')
            ]
        ]);
    });
});

// Health check endpoints
$app->get('/health', function() {
    return [
        'status' => 'healthy',
        'timestamp' => time(),
        'libraries' => [
            'monitoring' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
            'tracing' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')
        ],
        'realtime' => [
            'websocket_enabled' => !empty($_ENV['WEBSOCKET_ENABLED']),
            'sse_enabled' => !empty($_ENV['SSE_ENABLED'])
        ]
    ];
});

echo "📊 HighPer Realtime with Monitoring Only\n";
echo "🔧 Configuration:\n";
echo "   - Monitoring: " . (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor') ? 'Available' : 'Not Available') . "\n";
echo "   - Tracing: " . (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace') ? 'Available' : 'Not Available') . "\n";
echo "🌐 Endpoints:\n";
echo "   - HTTP: http://localhost:8080/\n";
echo "   - WebSocket: ws://localhost:8080/ws\n";
echo "   - SSE: http://localhost:8080/events\n";
echo "   - Health: http://localhost:8080/health\n";

if (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor')) {
    echo "   - Monitoring Health: http://localhost:8080/health/monitoring\n";
    echo "   - Metrics: http://localhost:8080/metrics (if Prometheus enabled)\n";
}

echo "\n🚀 Starting server...\n";

// Start the application
$app->start();