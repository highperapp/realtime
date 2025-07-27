<?php

/**
 * Example: HighPer Realtime with Tracing Library Only
 * 
 * This example demonstrates how to use the HighPer realtime library
 * with only the tracing library installed (no monitoring).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Container\Container;
use HighPerApp\HighPer\Http\Router;

// Create HighPer application
$container = new Container();
$router = new Router($container);
$app = new Application($container, $router);

// Environment configuration for tracing-only setup
$_ENV['MONITORING_ENABLED'] = 'false';  // Explicitly disable monitoring
$_ENV['TRACING_ENABLED'] = 'true';
$_ENV['REALTIME_ENABLED'] = 'true';
$_ENV['WEBSOCKET_ENABLED'] = 'true';
$_ENV['SSE_ENABLED'] = 'true';

// Tracing configuration
$_ENV['SERVICE_NAME'] = 'highper-realtime-tracing-example';
$_ENV['SERVICE_VERSION'] = '1.0.0';
$_ENV['TRACING_SAMPLING_RATE'] = '1.0';  // 100% sampling for demo

// Application will auto-discover tracing but skip monitoring
// TelemetryServiceProvider will gracefully handle missing monitoring library

// Basic HTTP endpoint with automatic tracing
$app->get('/', function() {
    return ['message' => 'HighPer Realtime with Tracing Only', 'timestamp' => time()];
});

// WebSocket endpoint with automatic distributed tracing
$app->websocket('/ws', function($connection, $message) {
    // Automatic tracing: spans for WebSocket connections, message processing
    $data = json_decode($message->getPayload(), true);
    
    switch ($data['type'] ?? 'echo') {
        case 'echo':
            // Each message creates a traced span
            $connection->send(['echo' => $data['message'] ?? 'Hello']);
            break;
            
        case 'broadcast':
            // Broadcast operation is traced with correlation IDs
            $connection->broadcast(['announcement' => $data['message']]);
            break;
            
        case 'trace_info':
            // If tracing library is available, return trace information
            if (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')) {
                $connection->send([
                    'trace_info' => [
                        'service_name' => $_ENV['SERVICE_NAME'] ?? 'unknown',
                        'active_spans' => 'Trace::getActiveSpanCount()',
                        'trace_id' => 'Trace::getCurrentTraceId()',
                        'span_id' => 'Trace::getCurrentSpanId()'
                    ]
                ]);
            } else {
                $connection->send(['error' => 'Tracing library not available']);
            }
            break;
    }
});

// SSE endpoint with automatic tracing
$app->sse('/events', function($stream) {
    // Send welcome event (traced)
    $stream->send(['type' => 'welcome', 'message' => 'Connected to tracing-enabled stream']);
    
    // Send periodic updates with trace information
    $timer = \Amp\repeat(3000, function() use ($stream) {
        $stream->send([
            'type' => 'trace_status',
            'data' => [
                'timestamp' => time(),
                'service' => $_ENV['SERVICE_NAME'] ?? 'unknown',
                'tracing_enabled' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace'),
                'monitoring_enabled' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
                'message' => 'This event is automatically traced with OpenTelemetry'
            ]
        ]);
    });
});

// Business logic example with manual span creation
$app->post('/api/process', function($request) {
    // Manual span creation for business logic
    if (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')) {
        return 'Trace::span("business.process", function() use ($request) {
            // Simulate business processing
            usleep(50000); // 50ms processing
            
            // Add business context to span
            Trace::addAttribute("business.operation", "data_processing");
            Trace::addAttribute("business.input_size", strlen($request->getBody()));
            
            return [
                "status" => "processed",
                "timestamp" => time(),
                "trace_id" => Trace::getCurrentTraceId()
            ];
        })';
    }
    
    return ['error' => 'Tracing library not available'];
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
        'tracing' => [
            'service_name' => $_ENV['SERVICE_NAME'] ?? 'unknown',
            'sampling_rate' => $_ENV['TRACING_SAMPLING_RATE'] ?? '0.01'
        ]
    ];
});

echo "🔍 HighPer Realtime with Tracing Only\n";
echo "🔧 Configuration:\n";
echo "   - Monitoring: " . (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor') ? 'Available' : 'Not Available') . "\n";
echo "   - Tracing: " . (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace') ? 'Available' : 'Not Available') . "\n";
echo "   - Service: " . ($_ENV['SERVICE_NAME'] ?? 'unknown') . "\n";
echo "🌐 Endpoints:\n";
echo "   - HTTP: http://localhost:8080/\n";
echo "   - WebSocket: ws://localhost:8080/ws\n";
echo "   - SSE: http://localhost:8080/events\n";
echo "   - Business API: POST http://localhost:8080/api/process\n";
echo "   - Health: http://localhost:8080/health\n";

if (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')) {
    echo "   - Tracing Health: http://localhost:8080/health/tracing\n";
}

echo "\n🚀 Starting server...\n";
echo "💡 All WebSocket, SSE, and HTTP operations will be automatically traced\n";
echo "💡 Traces include correlation IDs, W3C context propagation, and performance data\n";

// Start the application
$app->start();