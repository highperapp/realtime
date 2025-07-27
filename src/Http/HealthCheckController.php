<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Http;

use HighPerApp\HighPer\Monitoring\Facades\Monitor;
use HighPerApp\HighPer\Tracing\Facades\Trace;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Telemetry Health Check Controller
 * 
 * Provides comprehensive health check endpoints for monitoring
 * and observability systems in HighPer real-time applications.
 */
class HealthCheckController
{
    private LoggerInterface $logger;
    private array $config;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'detailed_health' => true,
            'include_metrics' => true,
            'include_traces' => true,
            'include_protocols' => true,
            'security_enabled' => false,
            'cache_duration' => 30 // seconds
        ], $config);
    }

    /**
     * Main telemetry health check endpoint
     */
    public function telemetryHealth(ServerRequestInterface $request): ResponseInterface
    {
        $startTime = microtime(true);
        
        try {
            $health = [
                'status' => 'healthy',
                'timestamp' => time(),
                'version' => $this->getFrameworkVersion(),
                'environment' => $_ENV['APP_ENV'] ?? 'production'
            ];

            // Basic monitoring health
            if (Monitor::isEnabled()) {
                $health['monitoring'] = Monitor::health();
            } else {
                $health['monitoring'] = ['status' => 'disabled'];
            }

            // Basic tracing health
            if (Trace::isEnabled()) {
                $health['tracing'] = Trace::health();
            } else {
                $health['tracing'] = ['status' => 'disabled'];
            }

            // Include detailed information if requested
            if ($this->config['detailed_health']) {
                $health['details'] = $this->getDetailedHealth();
            }

            // Include protocol health if enabled
            if ($this->config['include_protocols']) {
                $health['protocols'] = $this->getProtocolHealth();
            }

            // Calculate response time
            $health['response_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

            return $this->createJsonResponse($health, 200);

        } catch (\Throwable $e) {
            $this->logger->error('Health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorHealth = [
                'status' => 'unhealthy',
                'timestamp' => time(),
                'error' => $e->getMessage(),
                'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];

            return $this->createJsonResponse($errorHealth, 503);
        }
    }

    /**
     * Prometheus-compatible metrics endpoint
     */
    public function metrics(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (!Monitor::isEnabled()) {
                return $this->createTextResponse("# Monitoring disabled\n", 200);
            }

            $metrics = Monitor::exportPrometheus();
            
            // Add framework-specific metrics
            $frameworkMetrics = $this->getFrameworkMetrics();
            $combinedMetrics = $metrics . "\n" . $frameworkMetrics;

            return $this->createTextResponse($combinedMetrics, 200, [
                'Content-Type' => 'text/plain; version=0.0.4'
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Metrics export failed', [
                'error' => $e->getMessage()
            ]);

            return $this->createTextResponse("# Metrics export failed: {$e->getMessage()}\n", 500);
        }
    }

    /**
     * Monitoring-specific health check
     */
    public function monitoringHealth(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (!Monitor::isEnabled()) {
                return $this->createJsonResponse([
                    'status' => 'disabled',
                    'message' => 'Monitoring is not enabled'
                ], 200);
            }

            $health = Monitor::health();
            $health['timestamp'] = time();
            
            // Add monitoring-specific checks
            $health['checks'] = [
                'metrics_collection' => $this->checkMetricsCollection(),
                'storage_backend' => $this->checkStorageBackend(),
                'alerting_system' => $this->checkAlertingSystem(),
                'export_systems' => $this->checkExportSystems()
            ];

            $overallStatus = $this->determineOverallStatus($health['checks']);
            $statusCode = $overallStatus === 'healthy' ? 200 : 503;

            $health['status'] = $overallStatus;

            return $this->createJsonResponse($health, $statusCode);

        } catch (\Throwable $e) {
            $this->logger->error('Monitoring health check failed', [
                'error' => $e->getMessage()
            ]);

            return $this->createJsonResponse([
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tracing-specific health check
     */
    public function tracingHealth(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (!Trace::isEnabled()) {
                return $this->createJsonResponse([
                    'status' => 'disabled',
                    'message' => 'Tracing is not enabled'
                ], 200);
            }

            $health = Trace::health();
            $health['timestamp'] = time();
            
            // Add tracing-specific checks
            $health['checks'] = [
                'span_processing' => $this->checkSpanProcessing(),
                'trace_export' => $this->checkTraceExport(),
                'sampling_config' => $this->checkSamplingConfig(),
                'context_propagation' => $this->checkContextPropagation()
            ];

            $overallStatus = $this->determineOverallStatus($health['checks']);
            $statusCode = $overallStatus === 'healthy' ? 200 : 503;

            $health['status'] = $overallStatus;

            return $this->createJsonResponse($health, $statusCode);

        } catch (\Throwable $e) {
            $this->logger->error('Tracing health check failed', [
                'error' => $e->getMessage()
            ]);

            return $this->createJsonResponse([
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Protocol-specific health check
     */
    public function protocolHealth(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $protocolHealth = $this->getProtocolHealth();
            
            $overallStatus = $this->determineOverallStatus($protocolHealth);
            $statusCode = $overallStatus === 'healthy' ? 200 : 503;

            return $this->createJsonResponse([
                'status' => $overallStatus,
                'timestamp' => time(),
                'protocols' => $protocolHealth
            ], $statusCode);

        } catch (\Throwable $e) {
            $this->logger->error('Protocol health check failed', [
                'error' => $e->getMessage()
            ]);

            return $this->createJsonResponse([
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Readiness probe for Kubernetes/container orchestration
     */
    public function readiness(ServerRequestInterface $request): ResponseInterface
    {
        $checks = [
            'monitoring' => Monitor::isEnabled() ? $this->isMonitoringReady() : true,
            'tracing' => Trace::isEnabled() ? $this->isTracingReady() : true,
            'protocols' => $this->areProtocolsReady()
        ];

        $isReady = !in_array(false, $checks, true);
        
        $response = [
            'ready' => $isReady,
            'timestamp' => time(),
            'checks' => $checks
        ];

        return $this->createJsonResponse($response, $isReady ? 200 : 503);
    }

    /**
     * Liveness probe for Kubernetes/container orchestration
     */
    public function liveness(ServerRequestInterface $request): ResponseInterface
    {
        // Simple liveness check - if we can respond, we're alive
        try {
            $response = [
                'alive' => true,
                'timestamp' => time(),
                'uptime' => $this->getUptime(),
                'memory_usage' => [
                    'current' => memory_get_usage(true),
                    'peak' => memory_get_peak_usage(true)
                ]
            ];

            return $this->createJsonResponse($response, 200);

        } catch (\Throwable $e) {
            return $this->createJsonResponse([
                'alive' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed health information
     */
    private function getDetailedHealth(): array
    {
        $details = [
            'php_version' => PHP_VERSION,
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => ini_get('memory_limit')
            ],
            'uptime' => $this->getUptime(),
            'load_average' => $this->getLoadAverage()
        ];

        // Add monitoring details
        if (Monitor::isEnabled() && $this->config['include_metrics']) {
            $details['monitoring_stats'] = Monitor::stats();
        }

        // Add tracing details
        if (Trace::isEnabled() && $this->config['include_traces']) {
            $details['tracing_stats'] = Trace::stats();
        }

        return $details;
    }

    /**
     * Get protocol health status
     */
    private function getProtocolHealth(): array
    {
        $protocols = [];

        // Check WebSocket health
        if ($this->isProtocolEnabled('websocket')) {
            $protocols['websocket'] = $this->checkProtocolHealth('websocket');
        }

        // Check SSE health
        if ($this->isProtocolEnabled('sse')) {
            $protocols['sse'] = $this->checkProtocolHealth('sse');
        }

        // Check HTTP/3 health
        if ($this->isProtocolEnabled('http3')) {
            $protocols['http3'] = $this->checkProtocolHealth('http3');
        }

        // Check WebTransport health
        if ($this->isProtocolEnabled('webtransport')) {
            $protocols['webtransport'] = $this->checkProtocolHealth('webtransport');
        }

        return $protocols;
    }

    /**
     * Get framework-specific Prometheus metrics
     */
    private function getFrameworkMetrics(): string
    {
        $metrics = [];

        // Framework info
        $metrics[] = '# HELP highper_framework_info Framework information';
        $metrics[] = '# TYPE highper_framework_info gauge';
        $metrics[] = sprintf('highper_framework_info{version="%s",php_version="%s"} 1', 
            $this->getFrameworkVersion(), PHP_VERSION);

        // Memory metrics
        $metrics[] = '# HELP highper_memory_usage_bytes Memory usage in bytes';
        $metrics[] = '# TYPE highper_memory_usage_bytes gauge';
        $metrics[] = sprintf('highper_memory_usage_bytes{type="current"} %d', memory_get_usage(true));
        $metrics[] = sprintf('highper_memory_usage_bytes{type="peak"} %d', memory_get_peak_usage(true));

        // Protocol metrics
        foreach (['websocket', 'sse', 'http3', 'webtransport'] as $protocol) {
            if ($this->isProtocolEnabled($protocol)) {
                $metrics[] = sprintf('# HELP highper_protocol_enabled Protocol status');
                $metrics[] = sprintf('# TYPE highper_protocol_enabled gauge');
                $metrics[] = sprintf('highper_protocol_enabled{protocol="%s"} 1', $protocol);
            }
        }

        return implode("\n", $metrics);
    }

    /**
     * Helper methods for health checks
     */
    private function checkMetricsCollection(): string
    {
        try {
            Monitor::increment('health_check.test');
            return 'healthy';
        } catch (\Throwable $e) {
            return 'unhealthy';
        }
    }

    private function checkStorageBackend(): string
    {
        // This would check the configured storage backend
        return 'healthy';
    }

    private function checkAlertingSystem(): string
    {
        // This would check the alerting system status
        return 'healthy';
    }

    private function checkExportSystems(): string
    {
        // This would check configured export systems (Prometheus, etc.)
        return 'healthy';
    }

    private function checkSpanProcessing(): string
    {
        try {
            Trace::span('health_check.test', function() {
                return true;
            });
            return 'healthy';
        } catch (\Throwable $e) {
            return 'unhealthy';
        }
    }

    private function checkTraceExport(): string
    {
        // This would check trace export systems
        return 'healthy';
    }

    private function checkSamplingConfig(): string
    {
        // This would validate sampling configuration
        return 'healthy';
    }

    private function checkContextPropagation(): string
    {
        // This would check context propagation
        return 'healthy';
    }

    private function checkProtocolHealth(string $protocol): array
    {
        // This would check specific protocol health
        return [
            'status' => 'healthy',
            'enabled' => true,
            'connections' => $this->getProtocolConnectionCount($protocol),
            'last_check' => time()
        ];
    }

    private function isMonitoringReady(): bool
    {
        try {
            Monitor::health();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isTracingReady(): bool
    {
        try {
            Trace::health();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function areProtocolsReady(): bool
    {
        // Check if at least one protocol is ready
        $protocols = ['websocket', 'sse', 'http3', 'webtransport'];
        
        foreach ($protocols as $protocol) {
            if ($this->isProtocolEnabled($protocol)) {
                return true; // At least one protocol is enabled
            }
        }
        
        return true; // No protocols configured is OK
    }

    private function determineOverallStatus(array $checks): string
    {
        foreach ($checks as $check) {
            if (is_array($check) && isset($check['status'])) {
                if ($check['status'] !== 'healthy') {
                    return 'unhealthy';
                }
            } elseif (is_string($check) && $check !== 'healthy') {
                return 'unhealthy';
            }
        }
        
        return 'healthy';
    }

    private function getFrameworkVersion(): string
    {
        return '3.0.0'; // This would come from actual framework version
    }

    private function getUptime(): int
    {
        // This would track actual application uptime
        return time() - ($_SERVER['REQUEST_TIME'] ?? time());
    }

    private function getLoadAverage(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                '1min' => $load[0] ?? null,
                '5min' => $load[1] ?? null,
                '15min' => $load[2] ?? null
            ];
        }
        
        return null;
    }

    private function isProtocolEnabled(string $protocol): bool
    {
        return !empty($_ENV[strtoupper($protocol) . '_ENABLED']);
    }

    private function getProtocolConnectionCount(string $protocol): int
    {
        // This would get actual connection count from protocol
        return 0;
    }

    private function createJsonResponse(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'Cache-Control' => "max-age={$this->config['cache_duration']}"
        ];
        
        $headers = array_merge($defaultHeaders, $headers);
        
        // This would use the actual framework's response factory
        return new class($data, $status, $headers) implements ResponseInterface {
            private $data;
            private $status;
            private $headers;
            
            public function __construct($data, $status, $headers) {
                $this->data = $data;
                $this->status = $status;
                $this->headers = $headers;
            }
            
            public function getStatusCode(): int { return $this->status; }
            public function getBody() { return json_encode($this->data); }
            public function getHeaders(): array { return $this->headers; }
            public function getHeaderLine($name): string { return $this->headers[$name] ?? ''; }
            public function hasHeader($name): bool { return isset($this->headers[$name]); }
            public function getHeader($name): array { return [$this->headers[$name] ?? '']; }
            public function withStatus($code, $reasonPhrase = ''): ResponseInterface { return $this; }
            public function getReasonPhrase(): string { return ''; }
            public function getProtocolVersion(): string { return '1.1'; }
            public function withProtocolVersion($version): ResponseInterface { return $this; }
            public function withHeader($name, $value): ResponseInterface { return $this; }
            public function withAddedHeader($name, $value): ResponseInterface { return $this; }
            public function withoutHeader($name): ResponseInterface { return $this; }
            public function withBody($body): ResponseInterface { return $this; }
        };
    }

    private function createTextResponse(string $content, int $status = 200, array $headers = []): ResponseInterface
    {
        $defaultHeaders = [
            'Content-Type' => 'text/plain',
            'Cache-Control' => "max-age={$this->config['cache_duration']}"
        ];
        
        $headers = array_merge($defaultHeaders, $headers);
        
        // Simplified response implementation for text content
        return new class($content, $status, $headers) implements ResponseInterface {
            private $content;
            private $status;
            private $headers;
            
            public function __construct($content, $status, $headers) {
                $this->content = $content;
                $this->status = $status;
                $this->headers = $headers;
            }
            
            public function getStatusCode(): int { return $this->status; }
            public function getBody() { return $this->content; }
            public function getHeaders(): array { return $this->headers; }
            public function getHeaderLine($name): string { return $this->headers[$name] ?? ''; }
            public function hasHeader($name): bool { return isset($this->headers[$name]); }
            public function getHeader($name): array { return [$this->headers[$name] ?? '']; }
            public function withStatus($code, $reasonPhrase = ''): ResponseInterface { return $this; }
            public function getReasonPhrase(): string { return ''; }
            public function getProtocolVersion(): string { return '1.1'; }
            public function withProtocolVersion($version): ResponseInterface { return $this; }
            public function withHeader($name, $value): ResponseInterface { return $this; }
            public function withAddedHeader($name, $value): ResponseInterface { return $this; }
            public function withoutHeader($name): ResponseInterface { return $this; }
            public function withBody($body): ResponseInterface { return $this; }
        };
    }
}