<?php

/**
 * Example: HighPer Realtime with Both Monitoring and Tracing Libraries
 * 
 * This example demonstrates the complete observability stack with both
 * monitoring and tracing libraries for enterprise-grade applications.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HighPerApp\HighPer\Foundation\Application;
use HighPerApp\HighPer\Container\Container;
use HighPerApp\HighPer\Http\Router;

// Create HighPer application
$container = new Container();
$router = new Router($container);
$app = new Application($container, $router);

// Environment configuration for full observability
$_ENV['TELEMETRY_ENABLED'] = 'true';  // Enable both monitoring and tracing
$_ENV['MONITORING_ENABLED'] = 'true';
$_ENV['TRACING_ENABLED'] = 'true';
$_ENV['REALTIME_ENABLED'] = 'true';
$_ENV['WEBSOCKET_ENABLED'] = 'true';
$_ENV['SSE_ENABLED'] = 'true';
$_ENV['HTTP3_ENABLED'] = 'false';  // Disabled for demo
$_ENV['WEBTRANSPORT_ENABLED'] = 'false';  // Disabled for demo

// Monitoring configuration
$_ENV['MONITORING_SAMPLING_RATE'] = '0.5';  // 50% sampling
$_ENV['MONITORING_SECURITY_ENABLED'] = 'true';
$_ENV['PROMETHEUS_ENABLED'] = 'true';

// Tracing configuration
$_ENV['TRACING_SAMPLING_RATE'] = '0.2';  // 20% sampling
$_ENV['SERVICE_NAME'] = 'highper-realtime-full-example';
$_ENV['SERVICE_VERSION'] = '1.0.0';
$_ENV['TRACING_ENHANCED'] = 'true';  // Enhanced mode for enterprise features

// Export configuration
$_ENV['TRACING_JAEGER_ENABLED'] = 'false';  // Set to true if Jaeger available
$_ENV['TRACING_CONSOLE_ENABLED'] = 'true';  // Console export for demo

// Application will auto-discover both libraries and provide full observability

// Basic HTTP endpoint with full monitoring and tracing
$app->get('/', function() {
    return [
        'message' => 'HighPer Realtime with Full Observability Stack', 
        'timestamp' => time(),
        'capabilities' => [
            'monitoring' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
            'tracing' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace'),
            'security_monitoring' => !empty($_ENV['MONITORING_SECURITY_ENABLED']),
            'distributed_tracing' => !empty($_ENV['TRACING_ENABLED'])
        ]
    ];
});

// WebSocket endpoint with comprehensive observability
$app->websocket('/ws', function($connection, $message) {
    // Automatic monitoring: metrics, performance, security
    // Automatic tracing: spans, correlation IDs, distributed context
    
    $data = json_decode($message->getPayload(), true);
    
    switch ($data['type'] ?? 'echo') {
        case 'echo':
            $connection->send(['echo' => $data['message'] ?? 'Hello']);
            break;
            
        case 'broadcast':
            $connection->broadcast(['announcement' => $data['message']]);
            break;
            
        case 'performance_test':
            // Simulate processing with monitoring and tracing
            $startTime = microtime(true);
            
            // Simulate some work
            usleep(rand(10000, 100000)); // 10-100ms random delay
            
            $duration = microtime(true) - $startTime;
            
            $connection->send([
                'performance_test' => [
                    'duration_ms' => round($duration * 1000, 2),
                    'monitored' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
                    'traced' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace'),
                    'timestamp' => time()
                ]
            ]);
            break;
            
        case 'observability_status':
            $connection->send([
                'observability' => [
                    'monitoring' => [
                        'available' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
                        'sampling_rate' => $_ENV['MONITORING_SAMPLING_RATE'] ?? '0.1',
                        'security_enabled' => !empty($_ENV['MONITORING_SECURITY_ENABLED']),
                        'prometheus_enabled' => !empty($_ENV['PROMETHEUS_ENABLED'])
                    ],
                    'tracing' => [
                        'available' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace'),
                        'sampling_rate' => $_ENV['TRACING_SAMPLING_RATE'] ?? '0.05',
                        'enhanced_mode' => !empty($_ENV['TRACING_ENHANCED']),
                        'service_name' => $_ENV['SERVICE_NAME'] ?? 'unknown'
                    ]
                ]
            ]);
            break;
    }
});

// SSE endpoint with real-time observability metrics
$app->sse('/metrics-stream', function($stream) {
    $stream->send(['type' => 'welcome', 'message' => 'Live observability metrics stream']);
    
    // Send real-time metrics every 2 seconds
    $timer = \Amp\repeat(2000, function() use ($stream) {
        $metrics = [
            'timestamp' => time(),
            'system' => [
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ],
            'observability' => [
                'monitoring_enabled' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
                'tracing_enabled' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')
            ]
        ];
        
        // Add monitoring metrics if available
        if (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor')) {
            $metrics['monitoring'] = [
                'note' => 'Real monitoring metrics would be available here',
                'example_metrics' => [
                    'websocket_connections' => 'Monitor::getValue("websocket.connections")',
                    'http_requests_total' => 'Monitor::getValue("http.requests.total")',
                    'security_events' => 'Monitor::getValue("security.events")'
                ]
            ];
        }
        
        // Add tracing information if available
        if (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')) {
            $metrics['tracing'] = [
                'note' => 'Real tracing information would be available here',
                'example_traces' => [
                    'active_spans' => 'Trace::getActiveSpanCount()',
                    'trace_id' => 'Trace::getCurrentTraceId()',
                    'spans_exported' => 'Trace::getExportedSpanCount()'
                ]
            ];
        }
        
        $stream->send(['type' => 'metrics_update', 'data' => $metrics]);
    });
});

// Business logic with comprehensive observability
$app->post('/api/order', function($request) {
    // This endpoint demonstrates how business logic benefits from both libraries
    $orderData = json_decode($request->getBody(), true);
    
    // Simulate order processing with full observability
    $result = [
        'order_id' => uniqid('order_'),
        'status' => 'processed',
        'timestamp' => time(),
        'observability' => [
            'monitored' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
            'traced' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')
        ]
    ];
    
    // Add monitoring context
    if (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor')) {
        $result['monitoring'] = [
            'note' => 'Order processing metrics recorded',
            'examples' => [
                'orders.processed' => 'Monitor::increment("orders.processed")',
                'order.value' => 'Monitor::gauge("order.value", $orderValue)',
                'order.processing_time' => 'Monitor::timing("order.processing", $duration)'
            ]
        ];
    }
    
    // Add tracing context
    if (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')) {
        $result['tracing'] = [
            'note' => 'Order processing traced end-to-end',
            'examples' => [
                'trace_id' => 'Trace::getCurrentTraceId()',
                'span_context' => 'order.process span with business attributes',
                'correlation' => 'Full request correlation across services'
            ]
        ];
    }
    
    return $result;
});

// Comprehensive health check
$app->get('/health/comprehensive', function() {
    $health = [
        'status' => 'healthy',
        'timestamp' => time(),
        'service' => [
            'name' => $_ENV['SERVICE_NAME'] ?? 'highper-realtime',
            'version' => $_ENV['SERVICE_VERSION'] ?? '1.0.0'
        ],
        'libraries' => [
            'monitoring' => [
                'available' => class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor'),
                'config' => [
                    'sampling_rate' => $_ENV['MONITORING_SAMPLING_RATE'] ?? '0.1',
                    'security_enabled' => !empty($_ENV['MONITORING_SECURITY_ENABLED']),
                    'prometheus_enabled' => !empty($_ENV['PROMETHEUS_ENABLED'])
                ]
            ],
            'tracing' => [
                'available' => class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace'),
                'config' => [
                    'sampling_rate' => $_ENV['TRACING_SAMPLING_RATE'] ?? '0.05',
                    'enhanced_mode' => !empty($_ENV['TRACING_ENHANCED']),
                    'jaeger_enabled' => !empty($_ENV['TRACING_JAEGER_ENABLED'])
                ]
            ]
        ],
        'realtime' => [
            'websocket_enabled' => !empty($_ENV['WEBSOCKET_ENABLED']),
            'sse_enabled' => !empty($_ENV['SSE_ENABLED']),
            'http3_enabled' => !empty($_ENV['HTTP3_ENABLED']),
            'webtransport_enabled' => !empty($_ENV['WEBTRANSPORT_ENABLED'])
        ]
    ];
    
    return $health;
});

echo "🔍📊 HighPer Realtime with Full Observability Stack\n";
echo "════════════════════════════════════════════════════\n";
echo "🔧 Configuration:\n";
echo "   - Monitoring: " . (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor') ? '✅ Available' : '❌ Not Available') . "\n";
echo "   - Tracing: " . (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace') ? '✅ Available' : '❌ Not Available') . "\n";
echo "   - Service: " . ($_ENV['SERVICE_NAME'] ?? 'unknown') . "\n";
echo "   - Monitoring Sampling: " . ($_ENV['MONITORING_SAMPLING_RATE'] ?? '0.1') . "\n";
echo "   - Tracing Sampling: " . ($_ENV['TRACING_SAMPLING_RATE'] ?? '0.05') . "\n";
echo "\n🌐 Endpoints:\n";
echo "   - Main: http://localhost:8080/\n";
echo "   - WebSocket: ws://localhost:8080/ws\n";
echo "   - Live Metrics: http://localhost:8080/metrics-stream\n";
echo "   - Business API: POST http://localhost:8080/api/order\n";
echo "   - Health: http://localhost:8080/health/comprehensive\n";

// Health check endpoints
if (class_exists('\\HighPerApp\\HighPer\\Monitoring\\Facades\\Monitor')) {
    echo "   - Monitoring Health: http://localhost:8080/health/monitoring\n";
    echo "   - Prometheus Metrics: http://localhost:8080/metrics\n";
}

if (class_exists('\\HighPerApp\\HighPer\\Tracing\\Facades\\Trace')) {
    echo "   - Tracing Health: http://localhost:8080/health/tracing\n";
}

echo "\n🚀 Starting server...\n";
echo "💡 Features enabled:\n";
echo "   ✨ Automatic protocol instrumentation\n";
echo "   📊 Real-time performance metrics\n";
echo "   🔍 Distributed tracing with correlation\n";
echo "   🛡️ Security monitoring and threat detection\n";
echo "   📈 Business metrics and KPI tracking\n";
echo "   🎯 Health checks and readiness probes\n";
echo "   📤 Prometheus and OpenTelemetry export\n";

// Start the application
$app->start();