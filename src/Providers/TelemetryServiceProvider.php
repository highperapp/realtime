<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Providers;

use HighPerApp\HighPer\ServiceProvider\CoreServiceProvider;
use Psr\Container\ContainerInterface;

/**
 * Telemetry Service Provider
 * 
 * Auto-registers monitoring and tracing services when enabled
 * through environment variables with zero configuration required.
 */
class TelemetryServiceProvider extends CoreServiceProvider
{
    /**
     * Auto-discovery: Register only if telemetry is enabled
     */
    public static function shouldAutoLoad(): bool
    {
        return !empty($_ENV['TELEMETRY_ENABLED']) ||
               !empty($_ENV['MONITORING_ENABLED']) ||
               !empty($_ENV['TRACING_ENABLED']) ||
               !empty($_ENV['OBSERVABILITY_ENABLED']);
    }

    /**
     * Register telemetry orchestration services
     */
    public function register(ContainerInterface $container): void
    {
        // Get configuration with smart defaults
        $config = $this->getTelemetryConfig();
        
        // Skip if disabled
        if (!$config['enabled']) {
            return;
        }

        // Register monitoring library (if available and enabled)
        if ($config['monitoring']['enabled'] && $this->isMonitoringLibraryAvailable()) {
            $this->registerMonitoringLibrary($container, $config['monitoring']);
        }

        // Register tracing library (if available and enabled)
        if ($config['tracing']['enabled'] && $this->isTracingLibraryAvailable()) {
            $this->registerTracingLibrary($container, $config['tracing']);
        }

        // Register combined health check endpoints
        $this->registerHealthChecks($container);
    }

    /**
     * Boot telemetry services with automatic instrumentation
     */
    public function boot(ApplicationInterface $app): void
    {
        $config = $this->getTelemetryConfig();
        
        if (!$config['enabled']) {
            return;
        }

        // Auto-instrument real-time protocols
        if ($config['auto_instrumentation']) {
            $this->autoInstrumentProtocols($app);
        }

        // Setup health check routes
        $this->setupHealthRoutes($app);
        
        // Register shutdown handler for graceful cleanup
        $this->registerShutdownHandler();
    }

    /**
     * Get telemetry configuration with smart defaults
     */
    private function getTelemetryConfig(): array
    {
        // Use HighPer's pattern: environment-driven with smart defaults
        return [
            'enabled' => $this->isAnyTelemetryEnabled(),
            'auto_instrumentation' => $_ENV['TELEMETRY_AUTO_INSTRUMENT'] ?? true,
            
            'monitoring' => [
                'enabled' => $_ENV['MONITORING_ENABLED'] ?? $this->shouldEnableMonitoring(),
                'sampling_rate' => $this->getOptimalSamplingRate('monitoring'),
                'async_processing' => $_ENV['MONITORING_ASYNC'] ?? true,
                'features' => $this->getEnabledMonitoringFeatures()
            ],
            
            'tracing' => [
                'enabled' => $_ENV['TRACING_ENABLED'] ?? $this->shouldEnableTracing(),
                'sampling_rate' => $this->getOptimalSamplingRate('tracing'),
                'enhanced_mode' => $_ENV['TRACING_ENHANCED'] ?? $this->isEnhancedModeRecommended(),
                'exporters' => $this->getConfiguredExporters()
            ]
        ];
    }

    /**
     * Register monitoring library with optimal configuration
     */
    private function registerMonitoringLibrary(ContainerInterface $container, array $config): void
    {
        try {
            // Load MonitoringServiceProvider from the monitoring library
            $monitoringProvider = new \HighPerApp\HighPer\Monitoring\Providers\MonitoringServiceProvider($container);
            $monitoringProvider->register($container);
            
            $this->logger->info('Monitoring library registered successfully', [
                'sampling_rate' => $config['sampling_rate'],
                'features' => $config['features']
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to register monitoring library', [
                'error' => $e->getMessage(),
                'suggestion' => 'Install: composer require highperapp/monitoring'
            ]);
        }
    }

    /**
     * Register tracing library with optimal configuration
     */
    private function registerTracingLibrary(ContainerInterface $container, array $config): void
    {
        try {
            // Load TracingServiceProvider from the tracing library
            $tracingProvider = new \HighPerApp\HighPer\Tracing\Providers\TracingServiceProvider($container);
            $tracingProvider->register($container);
            
            $this->logger->info('Tracing library registered successfully', [
                'sampling_rate' => $config['sampling_rate'],
                'enhanced_mode' => $config['enhanced_mode']
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to register tracing library', [
                'error' => $e->getMessage(),
                'suggestion' => 'Install: composer require highperapp/tracing'
            ]);
        }
    }

    /**
     * Auto-instrument real-time protocols with minimal overhead
     */
    private function autoInstrumentProtocols(ApplicationInterface $app): void
    {
        $telemetryConfig = $this->getTelemetryConfig();
        
        // WebSocket instrumentation
        if ($this->isProtocolEnabled('websocket')) {
            $this->instrumentWebSocket($app, $telemetryConfig);
        }

        // SSE instrumentation
        if ($this->isProtocolEnabled('sse')) {
            $this->instrumentSSE($app, $telemetryConfig);
        }

        // HTTP/3 instrumentation
        if ($this->isProtocolEnabled('http3')) {
            $this->instrumentHTTP3($app, $telemetryConfig);
        }

        // WebTransport instrumentation
        if ($this->isProtocolEnabled('webtransport')) {
            $this->instrumentWebTransport($app, $telemetryConfig);
        }
    }

    /**
     * WebSocket auto-instrumentation
     */
    private function instrumentWebSocket(ApplicationInterface $app, array $telemetryConfig): void
    {
        try {
            $wsProtocol = $app->protocol('websocket');
            
            $instrumentationConfig = array_merge($telemetryConfig, [
                'sample_rate' => $telemetryConfig['monitoring']['sampling_rate'] ?? 0.1,
                'monitoring_available' => $this->isMonitoringLibraryAvailable(),
                'tracing_available' => $this->isTracingLibraryAvailable()
            ]);
            
            $instrumentation = new \HighPerApp\HighPer\Realtime\Instrumentation\WebSocketInstrumentation(
                $instrumentationConfig, 
                $this->logger
            );
            
            $instrumentation->instrument($wsProtocol);
            
            $this->logger->info('WebSocket protocol instrumented successfully');
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to instrument WebSocket protocol', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * SSE auto-instrumentation
     */
    private function instrumentSSE(ApplicationInterface $app, array $telemetryConfig): void
    {
        try {
            $sseProtocol = $app->protocol('sse');
            
            $instrumentationConfig = array_merge($telemetryConfig, [
                'sample_rate' => $telemetryConfig['monitoring']['sampling_rate'] ?? 0.15,
                'monitoring_available' => $this->isMonitoringLibraryAvailable(),
                'tracing_available' => $this->isTracingLibraryAvailable()
            ]);
            
            $instrumentation = new \HighPerApp\HighPer\Realtime\Instrumentation\SseInstrumentation(
                $instrumentationConfig, 
                $this->logger
            );
            
            $instrumentation->instrument($sseProtocol);
            
            $this->logger->info('SSE protocol instrumented successfully');
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to instrument SSE protocol', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * HTTP/3 auto-instrumentation
     */
    private function instrumentHTTP3(ApplicationInterface $app, array $telemetryConfig): void
    {
        try {
            $http3Protocol = $app->protocol('http3');
            
            $instrumentationConfig = array_merge($telemetryConfig, [
                'sample_rate' => $telemetryConfig['monitoring']['sampling_rate'] ?? 0.2,
                'monitoring_available' => $this->isMonitoringLibraryAvailable(),
                'tracing_available' => $this->isTracingLibraryAvailable()
            ]);
            
            $instrumentation = new \HighPerApp\HighPer\Realtime\Instrumentation\Http3Instrumentation(
                $instrumentationConfig, 
                $this->logger
            );
            
            $instrumentation->instrument($http3Protocol);
            
            $this->logger->info('HTTP/3 protocol instrumented successfully');
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to instrument HTTP/3 protocol', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * WebTransport auto-instrumentation
     */
    private function instrumentWebTransport(ApplicationInterface $app, array $telemetryConfig): void
    {
        try {
            $webTransportProtocol = $app->protocol('webtransport');
            
            $instrumentationConfig = array_merge($telemetryConfig, [
                'sample_rate' => $telemetryConfig['monitoring']['sampling_rate'] ?? 0.1,
                'monitoring_available' => $this->isMonitoringLibraryAvailable(),
                'tracing_available' => $this->isTracingLibraryAvailable()
            ]);
            
            $instrumentation = new \HighPerApp\HighPer\Realtime\Instrumentation\WebTransportInstrumentation(
                $instrumentationConfig, 
                $this->logger
            );
            
            $instrumentation->instrument($webTransportProtocol);
            
            $this->logger->info('WebTransport protocol instrumented successfully');
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to instrument WebTransport protocol', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Setup zero-config health check routes
     */
    private function setupHealthRoutes(ApplicationInterface $app): void
    {
        $healthController = new \HighPerApp\HighPer\Realtime\Http\HealthCheckController(
            $this->config, 
            $this->logger
        );

        // Main telemetry health endpoint
        $app->get('/health/telemetry', [$healthController, 'telemetryHealth']);
        
        // Component-specific health endpoints
        $app->get('/health/monitoring', [$healthController, 'monitoringHealth']);
        $app->get('/health/tracing', [$healthController, 'tracingHealth']);
        $app->get('/health/protocols', [$healthController, 'protocolHealth']);
        
        // Kubernetes-style probes
        $app->get('/health/ready', [$healthController, 'readiness']);
        $app->get('/health/live', [$healthController, 'liveness']);
        
        // Alternative endpoints for compatibility
        $app->get('/readiness', [$healthController, 'readiness']);
        $app->get('/liveness', [$healthController, 'liveness']);

        // Metrics endpoint (if Prometheus enabled)
        if ($_ENV['PROMETHEUS_ENABLED'] ?? false) {
            $app->get('/metrics', [$healthController, 'metrics']);
        }

        $this->logger->info('Telemetry health check routes registered', [
            'endpoints' => [
                '/health/telemetry',
                '/health/monitoring', 
                '/health/tracing',
                '/health/protocols',
                '/health/ready',
                '/health/live',
                '/metrics'
            ]
        ]);
    }

    /**
     * Smart defaults and optimization methods
     */
    private function isAnyTelemetryEnabled(): bool
    {
        return !empty($_ENV['TELEMETRY_ENABLED']) ||
               !empty($_ENV['MONITORING_ENABLED']) ||
               !empty($_ENV['TRACING_ENABLED']) ||
               !empty($_ENV['OBSERVABILITY_ENABLED']) ||
               $this->isProductionEnvironment();
    }

    private function shouldEnableMonitoring(): bool
    {
        // Auto-enable in production or when real-time protocols are used
        return $this->isProductionEnvironment() || 
               $this->hasRealTimeProtocols();
    }

    private function shouldEnableTracing(): bool
    {
        // Enable tracing for distributed systems or debugging
        return $this->isDistributedSystem() || 
               $this->isDebugMode();
    }

    private function getOptimalSamplingRate(string $type): float
    {
        // Adaptive sampling based on environment and load
        $environment = $_ENV['APP_ENV'] ?? 'production';
        $load = $this->getExpectedLoad();
        
        if ($type === 'monitoring') {
            switch ($environment) {
                case 'development': return 1.0;
                case 'staging': return 0.5;
                case 'production': return $load > 10000 ? 0.1 : 0.5;
                default: return 0.1;
            }
        }
        
        if ($type === 'tracing') {
            switch ($environment) {
                case 'development': return 1.0;
                case 'staging': return 0.1;
                case 'production': return $load > 10000 ? 0.01 : 0.05;
                default: return 0.01;
            }
        }
        
        return 0.1;
    }

    private function getEnabledMonitoringFeatures(): array
    {
        $features = ['performance', 'health'];
        
        if ($this->isSecurityCritical()) {
            $features[] = 'security';
        }
        
        if ($this->hasHighThroughput()) {
            $features[] = 'metrics';
        }
        
        return $features;
    }

    // Helper methods for smart detection
    private function isProductionEnvironment(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'production') === 'production';
    }

    private function hasRealTimeProtocols(): bool
    {
        return !empty($_ENV['WEBSOCKET_ENABLED']) ||
               !empty($_ENV['SSE_ENABLED']) ||
               !empty($_ENV['WEBTRANSPORT_ENABLED']);
    }

    private function isDistributedSystem(): bool
    {
        return !empty($_ENV['MICROSERVICES_MODE']) ||
               !empty($_ENV['KUBERNETES_SERVICE_HOST']);
    }

    private function getExpectedLoad(): int
    {
        return (int) ($_ENV['EXPECTED_CONNECTIONS'] ?? 1000);
    }

    private function shouldSample(string $operation): bool
    {
        $rate = $this->getSamplingRate($operation);
        return mt_rand() / mt_getrandmax() < $rate;
    }

    /**
     * Check if monitoring library is available
     */
    private function isMonitoringLibraryAvailable(): bool
    {
        return class_exists('\\HighPerApp\\HighPer\\Monitoring\\Providers\\MonitoringServiceProvider');
    }

    /**
     * Check if tracing library is available
     */
    private function isTracingLibraryAvailable(): bool
    {
        return class_exists('\\HighPerApp\\HighPer\\Tracing\\Providers\\TracingServiceProvider');
    }

    /**
     * Get configured exporters for tracing
     */
    private function getConfiguredExporters(): array
    {
        return [
            'jaeger' => [
                'enabled' => $_ENV['TRACING_JAEGER_ENABLED'] ?? false,
                'endpoint' => $_ENV['TRACING_JAEGER_COLLECTOR_ENDPOINT'] ?? 'http://localhost:14268/api/traces'
            ],
            'zipkin' => [
                'enabled' => $_ENV['TRACING_ZIPKIN_ENABLED'] ?? false,
                'endpoint' => $_ENV['TRACING_ZIPKIN_ENDPOINT'] ?? 'http://localhost:9411/api/v2/spans'
            ],
            'console' => [
                'enabled' => $_ENV['TRACING_CONSOLE_ENABLED'] ?? ($_ENV['APP_ENV'] === 'development')
            ]
        ];
    }
}