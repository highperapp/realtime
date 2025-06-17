<?php

declare(strict_types=1);

namespace EaseAppPHP\HighPer\Realtime\Monitoring;

use EaseAppPHP\HighPer\Realtime\Protocols\ProtocolInterface;
use Psr\Log\LoggerInterface;

/**
 * Core Performance Monitor for Real-Time Protocols
 * 
 * Provides embedded performance monitoring for all real-time protocols:
 * - Protocol-specific metrics collection
 * - Real-time latency measurement  
 * - Bandwidth utilization tracking
 * - Connection health monitoring
 * - Performance data aggregation
 * - Lightweight metrics export for external dashboards
 */
class PerformanceMonitor
{
    private LoggerInterface $logger;
    private array $config;
    private MetricsCollector $metricsCollector;
    private LatencyTracker $latencyTracker;
    private BandwidthMonitor $bandwidthMonitor;
    private ConnectionHealthTracker $healthTracker;
    private PerformanceAggregator $aggregator;
    
    private array $protocolMonitors = [];
    private array $globalMetrics = [];
    private array $protocolMetrics = [];
    private array $alertRules = [];
    private bool $isRunning = false;
    private float $lastCollectionTime = 0;

    // Metric types
    private const METRIC_COUNTER = 'counter';
    private const METRIC_GAUGE = 'gauge';
    private const METRIC_HISTOGRAM = 'histogram';
    private const METRIC_TIMER = 'timer';

    // Performance categories
    private const CATEGORY_LATENCY = 'latency';
    private const CATEGORY_THROUGHPUT = 'throughput';
    private const CATEGORY_RELIABILITY = 'reliability';
    private const CATEGORY_RESOURCE = 'resource';

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->initializeComponents();
        $this->initializeMetrics();
        $this->setupAlertRules();
    }

    /**
     * Initialize monitoring components
     */
    private function initializeComponents(): void
    {
        $this->metricsCollector = new MetricsCollector($this->logger, $this->config);
        $this->latencyTracker = new LatencyTracker($this->logger, $this->config);
        $this->bandwidthMonitor = new BandwidthMonitor($this->logger, $this->config);
        $this->healthTracker = new ConnectionHealthTracker($this->logger, $this->config);
        $this->aggregator = new PerformanceAggregator($this->logger, $this->config);
    }

    /**
     * Initialize global metrics
     */
    private function initializeMetrics(): void
    {
        $this->globalMetrics = [
            // Connection metrics
            'total_connections' => 0,
            'active_connections' => 0,
            'connection_rate' => 0,
            'connection_failures' => 0,
            
            // Latency metrics
            'average_latency_ms' => 0,
            'p50_latency_ms' => 0,
            'p95_latency_ms' => 0,
            'p99_latency_ms' => 0,
            
            // Throughput metrics
            'messages_per_second' => 0,
            'bytes_per_second' => 0,
            'peak_throughput' => 0,
            
            // Reliability metrics
            'uptime_percentage' => 100.0,
            'error_rate' => 0,
            'timeout_rate' => 0,
            
            // Resource metrics
            'cpu_usage' => 0,
            'memory_usage' => 0,
            'network_utilization' => 0,
            
            // Protocol distribution
            'protocol_distribution' => [],
            
            // Performance score
            'overall_performance_score' => 0
        ];
    }

    /**
     * Setup alert rules
     */
    private function setupAlertRules(): void
    {
        $this->alertRules = [
            'high_latency' => [
                'metric' => 'average_latency_ms',
                'threshold' => $this->config['latency_alert_threshold'],
                'operator' => '>',
                'severity' => 'warning'
            ],
            'low_uptime' => [
                'metric' => 'uptime_percentage',
                'threshold' => $this->config['uptime_alert_threshold'],
                'operator' => '<',
                'severity' => 'critical'
            ],
            'high_error_rate' => [
                'metric' => 'error_rate',
                'threshold' => $this->config['error_rate_threshold'],
                'operator' => '>',
                'severity' => 'warning'
            ],
            'high_cpu_usage' => [
                'metric' => 'cpu_usage',
                'threshold' => $this->config['cpu_alert_threshold'],
                'operator' => '>',
                'severity' => 'warning'
            ]
        ];
    }

    /**
     * Register a protocol for monitoring
     */
    public function registerProtocol(string $protocolName, ProtocolInterface $protocol): void
    {
        $protocolMonitor = new ProtocolMonitor($protocolName, $protocol, $this->logger, $this->config);
        $this->protocolMonitors[$protocolName] = $protocolMonitor;
        
        // Initialize protocol-specific metrics
        $this->protocolMetrics[$protocolName] = [
            'connections' => 0,
            'messages_sent' => 0,
            'messages_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'errors' => 0,
            'average_latency' => 0,
            'throughput' => 0,
            'last_activity' => time()
        ];

        $this->logger->info('Protocol registered for monitoring', [
            'protocol' => $protocolName,
            'version' => $protocol->getVersion()
        ]);
    }

    /**
     * Start performance monitoring
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting Performance Monitor');

        // Start monitoring components
        yield $this->metricsCollector->start();
        yield $this->latencyTracker->start();
        yield $this->bandwidthMonitor->start();
        yield $this->healthTracker->start();

        // Start protocol monitors
        foreach ($this->protocolMonitors as $monitor) {
            yield $monitor->start();
        }

        $this->isRunning = true;
        $this->lastCollectionTime = microtime(true);

        // Start monitoring loop
        \Amp\async(function() {
            yield $this->startMonitoringLoop();
        });

        $this->logger->info('Performance Monitor started');
    }

    /**
     * Stop monitoring
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping Performance Monitor');

        $this->isRunning = false;

        $this->logger->info('Performance Monitor stopped');
    }

    /**
     * Record a metric
     */
    public function recordMetric(string $name, $value, string $type = self::METRIC_GAUGE, array $labels = []): void
    {
        $this->metricsCollector->record($name, $value, $type, $labels);
    }

    /**
     * Start latency measurement
     */
    public function startLatencyMeasurement(string $operation, array $context = []): string
    {
        return $this->latencyTracker->start($operation, $context);
    }

    /**
     * End latency measurement
     */
    public function endLatencyMeasurement(string $measurementId): float
    {
        return $this->latencyTracker->end($measurementId);
    }

    /**
     * Record bandwidth usage
     */
    public function recordBandwidth(string $protocol, int $bytesSent, int $bytesReceived): void
    {
        $this->bandwidthMonitor->record($protocol, $bytesSent, $bytesReceived);
    }

    /**
     * Record connection event
     */
    public function recordConnectionEvent(string $protocol, string $event, array $context = []): void
    {
        $this->healthTracker->recordEvent($protocol, $event, $context);
        
        // Update protocol metrics
        if (isset($this->protocolMetrics[$protocol])) {
            switch ($event) {
                case 'connect':
                    $this->protocolMetrics[$protocol]['connections']++;
                    break;
                case 'disconnect':
                    $this->protocolMetrics[$protocol]['connections']--;
                    break;
                case 'error':
                    $this->protocolMetrics[$protocol]['errors']++;
                    break;
            }
            $this->protocolMetrics[$protocol]['last_activity'] = time();
        }
    }

    /**
     * Record message event
     */
    public function recordMessageEvent(string $protocol, string $direction, int $bytes, float $latency = null): void
    {
        if (!isset($this->protocolMetrics[$protocol])) {
            return;
        }

        $metrics = &$this->protocolMetrics[$protocol];
        
        if ($direction === 'sent') {
            $metrics['messages_sent']++;
            $metrics['bytes_sent'] += $bytes;
        } else {
            $metrics['messages_received']++;
            $metrics['bytes_received'] += $bytes;
        }

        if ($latency !== null) {
            // Update average latency using exponential moving average
            $alpha = 0.1;
            $metrics['average_latency'] = (1 - $alpha) * $metrics['average_latency'] + $alpha * $latency;
        }

        $metrics['last_activity'] = time();
        
        // Record in global bandwidth monitor
        if ($direction === 'sent') {
            $this->recordBandwidth($protocol, $bytes, 0);
        } else {
            $this->recordBandwidth($protocol, 0, $bytes);
        }
    }

    /**
     * Get current performance metrics
     */
    public function getMetrics(): array
    {
        // Update global metrics
        $this->updateGlobalMetrics();
        
        return [
            'timestamp' => time(),
            'collection_interval' => $this->config['collection_interval'],
            'global' => $this->globalMetrics,
            'protocols' => $this->protocolMetrics,
            'latency' => $this->latencyTracker->getMetrics(),
            'bandwidth' => $this->bandwidthMonitor->getMetrics(),
            'health' => $this->healthTracker->getMetrics(),
            'alerts' => $this->checkAlerts()
        ];
    }

    /**
     * Get protocol-specific metrics
     */
    public function getProtocolMetrics(string $protocol): array
    {
        if (!isset($this->protocolMetrics[$protocol])) {
            return [];
        }

        $metrics = $this->protocolMetrics[$protocol];
        
        // Add derived metrics
        $timeSinceLastActivity = time() - $metrics['last_activity'];
        $metrics['is_active'] = $timeSinceLastActivity < $this->config['activity_timeout'];
        
        // Calculate throughput (messages per second)
        if ($timeSinceLastActivity < 60) { // Last minute
            $totalMessages = $metrics['messages_sent'] + $metrics['messages_received'];
            $metrics['throughput'] = $totalMessages / max(1, $timeSinceLastActivity);
        }

        return $metrics;
    }

    /**
     * Get performance summary
     */
    public function getPerformanceSummary(): array
    {
        $metrics = $this->getMetrics();
        
        return [
            'overall_score' => $this->calculateOverallPerformanceScore($metrics),
            'status' => $this->determineOverallStatus($metrics),
            'top_protocols' => $this->getTopPerformingProtocols(),
            'bottlenecks' => $this->identifyBottlenecks($metrics),
            'recommendations' => $this->generateRecommendations($metrics)
        ];
    }

    /**
     * Export metrics for external monitoring systems
     */
    public function exportMetrics(string $format = 'prometheus'): string
    {
        $metrics = $this->getMetrics();
        
        switch ($format) {
            case 'prometheus':
                return $this->exportPrometheusFormat($metrics);
            case 'json':
                return json_encode($metrics, JSON_PRETTY_PRINT);
            case 'influxdb':
                return $this->exportInfluxDBFormat($metrics);
            default:
                throw new \InvalidArgumentException("Unsupported export format: {$format}");
        }
    }

    /**
     * Create performance snapshot
     */
    public function createSnapshot(): array
    {
        return [
            'timestamp' => microtime(true),
            'metrics' => $this->getMetrics(),
            'system_info' => $this->getSystemInfo(),
            'protocol_states' => $this->getProtocolStates()
        ];
    }

    /**
     * Monitor loop
     */
    private function startMonitoringLoop(): \Generator
    {
        while ($this->isRunning) {
            $startTime = microtime(true);
            
            try {
                // Collect metrics from all sources
                yield $this->collectMetrics();
                
                // Update aggregations
                yield $this->updateAggregations();
                
                // Check alerts
                $alerts = $this->checkAlerts();
                if (!empty($alerts)) {
                    yield $this->handleAlerts($alerts);
                }
                
                // Cleanup old data
                if (time() % $this->config['cleanup_interval'] === 0) {
                    yield $this->cleanupOldData();
                }

            } catch (\Throwable $e) {
                $this->logger->error('Error in performance monitoring loop', [
                    'error' => $e->getMessage()
                ]);
            }

            $processingTime = microtime(true) - $startTime;
            $sleepTime = max(0, $this->config['collection_interval'] - $processingTime * 1000);
            
            yield \Amp\delay((int)$sleepTime);
        }
    }

    /**
     * Update global metrics
     */
    private function updateGlobalMetrics(): void
    {
        // Calculate totals across all protocols
        $totalConnections = 0;
        $totalMessages = 0;
        $totalBytes = 0;
        $totalErrors = 0;
        $latencies = [];

        foreach ($this->protocolMetrics as $protocol => $metrics) {
            $totalConnections += $metrics['connections'];
            $totalMessages += $metrics['messages_sent'] + $metrics['messages_received'];
            $totalBytes += $metrics['bytes_sent'] + $metrics['bytes_received'];
            $totalErrors += $metrics['errors'];
            
            if ($metrics['average_latency'] > 0) {
                $latencies[] = $metrics['average_latency'];
            }
        }

        $this->globalMetrics['active_connections'] = $totalConnections;
        $this->globalMetrics['total_messages'] = $totalMessages;
        $this->globalMetrics['total_bytes'] = $totalBytes;
        $this->globalMetrics['total_errors'] = $totalErrors;

        // Calculate average latency
        if (!empty($latencies)) {
            $this->globalMetrics['average_latency_ms'] = array_sum($latencies) / count($latencies);
        }

        // Calculate error rate
        if ($totalMessages > 0) {
            $this->globalMetrics['error_rate'] = $totalErrors / $totalMessages;
        }

        // Update protocol distribution
        $this->globalMetrics['protocol_distribution'] = [];
        foreach ($this->protocolMetrics as $protocol => $metrics) {
            $this->globalMetrics['protocol_distribution'][$protocol] = $metrics['connections'];
        }

        // Get system metrics
        $this->globalMetrics['cpu_usage'] = $this->getCPUUsage();
        $this->globalMetrics['memory_usage'] = $this->getMemoryUsage();
        $this->globalMetrics['network_utilization'] = $this->bandwidthMonitor->getUtilization();

        // Calculate overall performance score
        $this->globalMetrics['overall_performance_score'] = $this->calculateOverallPerformanceScore([
            'global' => $this->globalMetrics
        ]);
    }

    /**
     * Check alert conditions
     */
    private function checkAlerts(): array
    {
        $activeAlerts = [];
        
        foreach ($this->alertRules as $alertName => $rule) {
            $metricValue = $this->globalMetrics[$rule['metric']] ?? 0;
            $threshold = $rule['threshold'];
            $operator = $rule['operator'];
            
            $alertTriggered = match($operator) {
                '>' => $metricValue > $threshold,
                '<' => $metricValue < $threshold,
                '>=' => $metricValue >= $threshold,
                '<=' => $metricValue <= $threshold,
                '==' => $metricValue == $threshold,
                default => false
            };

            if ($alertTriggered) {
                $activeAlerts[] = [
                    'name' => $alertName,
                    'severity' => $rule['severity'],
                    'metric' => $rule['metric'],
                    'current_value' => $metricValue,
                    'threshold' => $threshold,
                    'timestamp' => time()
                ];
            }
        }

        return $activeAlerts;
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'collection_interval' => 5000, // 5 seconds
            'cleanup_interval' => 300, // 5 minutes
            'activity_timeout' => 30, // seconds
            'latency_alert_threshold' => 1000, // 1 second
            'uptime_alert_threshold' => 95, // 95%
            'error_rate_threshold' => 0.05, // 5%
            'cpu_alert_threshold' => 80, // 80%
            'enable_detailed_logging' => false,
            'metric_retention_hours' => 24
        ];
    }

    // Placeholder methods for full implementation
    private function collectMetrics(): \Generator { return yield; }
    private function updateAggregations(): \Generator { return yield; }
    private function handleAlerts(array $alerts): \Generator { return yield; }
    private function cleanupOldData(): \Generator { return yield; }
    private function calculateOverallPerformanceScore(array $metrics): float { return 85.0; }
    private function determineOverallStatus(array $metrics): string { return 'healthy'; }
    private function getTopPerformingProtocols(): array { return []; }
    private function identifyBottlenecks(array $metrics): array { return []; }
    private function generateRecommendations(array $metrics): array { return []; }
    private function exportPrometheusFormat(array $metrics): string { return '# Prometheus format'; }
    private function exportInfluxDBFormat(array $metrics): string { return 'influxdb format'; }
    private function getSystemInfo(): array { return ['php_version' => PHP_VERSION]; }
    private function getProtocolStates(): array { return []; }
    private function getCPUUsage(): float { return 0.0; }
    private function getMemoryUsage(): float { return 0.0; }
}