<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Monitoring;

use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
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
            return yield;
        }

        $this->logger->info('Stopping Performance Monitor');

        $this->isRunning = false;

        $this->logger->info('Performance Monitor stopped');
        
        return yield;
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

    /**
     * Collect metrics from all monitored sources
     */
    private function collectMetrics(): \Generator
    {
        try {
            // Collect from each protocol monitor
            $protocolMetrics = [];
            foreach ($this->protocolMonitors as $protocolName => $monitor) {
                $protocolMetrics[$protocolName] = yield $monitor->collectMetrics();
            }
            
            // Collect system metrics
            $systemMetrics = [
                'cpu_usage' => $this->getCPUUsage(),
                'memory_usage' => $this->getMemoryUsage(),
                'disk_usage' => $this->getDiskUsage(),
                'network_stats' => $this->getNetworkStats()
            ];
            
            // Collect compression metrics if available
            $compressionMetrics = $this->getCompressionMetrics();
            
            // Store collected metrics
            $this->lastCollectionTime = microtime(true);
            
            return [
                'protocols' => $protocolMetrics,
                'system' => $systemMetrics,
                'compression' => $compressionMetrics,
                'timestamp' => time()
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Metrics collection failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Update metric aggregations
     */
    private function updateAggregations(): \Generator
    {
        try {
            // Calculate moving averages
            $this->updateMovingAverages();
            
            // Update percentiles
            $this->updatePercentiles();
            
            // Update protocol rankings
            $this->updateProtocolRankings();
            
            // Update trend analysis
            $this->updateTrendAnalysis();
            
            return yield;
        } catch (\Throwable $e) {
            $this->logger->error('Aggregation update failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle triggered alerts
     */
    private function handleAlerts(array $alerts): \Generator
    {
        foreach ($alerts as $alert) {
            try {
                // Log alert
                $this->logger->warning('Performance alert triggered', $alert);
                
                // Send notifications if configured
                if ($this->config['enable_notifications'] ?? false) {
                    yield $this->sendAlertNotification($alert);
                }
                
                // Auto-remediation for critical alerts
                if ($alert['severity'] === 'critical') {
                    yield $this->attemptAutoRemediation($alert);
                }
                
                // Record alert in history
                $this->recordAlert($alert);
                
            } catch (\Throwable $e) {
                $this->logger->error('Alert handling failed', [
                    'alert' => $alert,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Cleanup old metric data
     */
    private function cleanupOldData(): \Generator
    {
        $retentionHours = $this->config['metric_retention_hours'];
        $cutoffTime = time() - ($retentionHours * 3600);
        
        try {
            // Cleanup old protocol metrics
            foreach ($this->protocolMetrics as $protocol => $metrics) {
                if (isset($metrics['last_activity']) && $metrics['last_activity'] < $cutoffTime) {
                    unset($this->protocolMetrics[$protocol]);
                }
            }
            
            // Cleanup old performance data
            $this->cleanupOldPerformanceData($cutoffTime);
            
            $this->logger->debug('Old metrics data cleaned up', [
                'retention_hours' => $retentionHours,
                'cutoff_time' => date('Y-m-d H:i:s', $cutoffTime)
            ]);
            
            return yield;
        } catch (\Throwable $e) {
            $this->logger->error('Data cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Calculate overall performance score
     */
    private function calculateOverallPerformanceScore(array $metrics): float
    {
        $weights = [
            'latency' => 0.3,
            'throughput' => 0.25,
            'reliability' => 0.25,
            'resource_efficiency' => 0.2
        ];
        
        $scores = [];
        
        // Latency score (lower is better)
        $avgLatency = $metrics['global']['average_latency_ms'] ?? 100;
        $scores['latency'] = max(0, 100 - ($avgLatency / 10));
        
        // Throughput score
        $throughput = $metrics['global']['messages_per_second'] ?? 0;
        $scores['throughput'] = min(100, $throughput / 100);
        
        // Reliability score
        $uptime = $metrics['global']['uptime_percentage'] ?? 100;
        $errorRate = $metrics['global']['error_rate'] ?? 0;
        $scores['reliability'] = $uptime * (1 - $errorRate);
        
        // Resource efficiency score
        $cpuUsage = $metrics['global']['cpu_usage'] ?? 0;
        $memoryUsage = $metrics['global']['memory_usage'] ?? 0;
        $scores['resource_efficiency'] = max(0, 100 - (($cpuUsage + $memoryUsage) / 2));
        
        // Calculate weighted average
        $overallScore = 0;
        foreach ($weights as $metric => $weight) {
            $overallScore += ($scores[$metric] ?? 0) * $weight;
        }
        
        return round($overallScore, 2);
    }
    
    /**
     * Determine overall system status
     */
    private function determineOverallStatus(array $metrics): string
    {
        $score = $this->calculateOverallPerformanceScore($metrics);
        $errorRate = $metrics['global']['error_rate'] ?? 0;
        $uptime = $metrics['global']['uptime_percentage'] ?? 100;
        $latency = $metrics['global']['average_latency_ms'] ?? 0;
        
        // Critical conditions
        if ($uptime < 90 || $errorRate > 0.1 || $latency > 5000) {
            return 'critical';
        }
        
        // Warning conditions
        if ($score < 60 || $uptime < 95 || $errorRate > 0.05 || $latency > 2000) {
            return 'warning';
        }
        
        // Degraded conditions
        if ($score < 80 || $uptime < 98 || $errorRate > 0.02 || $latency > 1000) {
            return 'degraded';
        }
        
        return 'healthy';
    }
    
    /**
     * Get top performing protocols
     */
    private function getTopPerformingProtocols(): array
    {
        $protocolScores = [];
        
        foreach ($this->protocolMetrics as $protocol => $metrics) {
            $score = $this->calculateProtocolScore($protocol, $metrics);
            $protocolScores[$protocol] = $score;
        }
        
        arsort($protocolScores);
        
        return array_slice($protocolScores, 0, 5, true);
    }
    
    /**
     * Identify performance bottlenecks
     */
    private function identifyBottlenecks(array $metrics): array
    {
        $bottlenecks = [];
        
        // CPU bottleneck
        if (($metrics['global']['cpu_usage'] ?? 0) > 80) {
            $bottlenecks[] = [
                'type' => 'cpu',
                'severity' => 'high',
                'value' => $metrics['global']['cpu_usage'],
                'threshold' => 80,
                'description' => 'High CPU usage detected'
            ];
        }
        
        // Memory bottleneck
        if (($metrics['global']['memory_usage'] ?? 0) > 85) {
            $bottlenecks[] = [
                'type' => 'memory',
                'severity' => 'high',
                'value' => $metrics['global']['memory_usage'],
                'threshold' => 85,
                'description' => 'High memory usage detected'
            ];
        }
        
        // Latency bottleneck
        if (($metrics['global']['average_latency_ms'] ?? 0) > 1000) {
            $bottlenecks[] = [
                'type' => 'latency',
                'severity' => 'medium',
                'value' => $metrics['global']['average_latency_ms'],
                'threshold' => 1000,
                'description' => 'High average latency detected'
            ];
        }
        
        // Connection bottleneck
        $connectionCount = $metrics['global']['active_connections'] ?? 0;
        if ($connectionCount > 1000) {
            $bottlenecks[] = [
                'type' => 'connections',
                'severity' => 'medium',
                'value' => $connectionCount,
                'threshold' => 1000,
                'description' => 'High connection count detected'
            ];
        }
        
        return $bottlenecks;
    }
    
    /**
     * Generate performance recommendations
     */
    private function generateRecommendations(array $metrics): array
    {
        $recommendations = [];
        $bottlenecks = $this->identifyBottlenecks($metrics);
        
        foreach ($bottlenecks as $bottleneck) {
            switch ($bottleneck['type']) {
                case 'cpu':
                    $recommendations[] = [
                        'type' => 'optimization',
                        'priority' => 'high',
                        'action' => 'Enable connection pooling and reduce CPU-intensive operations',
                        'expected_impact' => 'Reduce CPU usage by 20-30%'
                    ];
                    break;
                    
                case 'memory':
                    $recommendations[] = [
                        'type' => 'optimization',
                        'priority' => 'high',
                        'action' => 'Enable compression and implement memory cleanup',
                        'expected_impact' => 'Reduce memory usage by 15-25%'
                    ];
                    break;
                    
                case 'latency':
                    $recommendations[] = [
                        'type' => 'optimization',
                        'priority' => 'medium',
                        'action' => 'Optimize protocol selection and enable HTTP/3',
                        'expected_impact' => 'Reduce latency by 30-50%'
                    ];
                    break;
                    
                case 'connections':
                    $recommendations[] = [
                        'type' => 'scaling',
                        'priority' => 'medium',
                        'action' => 'Implement connection multiplexing and load balancing',
                        'expected_impact' => 'Support 2-3x more connections'
                    ];
                    break;
            }
        }
        
        // Add general optimization recommendations
        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'optimization',
                'priority' => 'low',
                'action' => 'System is performing well - consider enabling advanced features',
                'expected_impact' => 'Further performance improvements'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Export metrics in Prometheus format
     */
    private function exportPrometheusFormat(array $metrics): string
    {
        $output = "# HELP realtime_connections_total Total number of connections\n";
        $output .= "# TYPE realtime_connections_total counter\n";
        $output .= "realtime_connections_total{type=\"active\"} " . ($metrics['global']['active_connections'] ?? 0) . "\n";
        
        $output .= "# HELP realtime_latency_seconds Average latency in seconds\n";
        $output .= "# TYPE realtime_latency_seconds gauge\n";
        $output .= "realtime_latency_seconds " . (($metrics['global']['average_latency_ms'] ?? 0) / 1000) . "\n";
        
        $output .= "# HELP realtime_throughput_messages_per_second Messages processed per second\n";
        $output .= "# TYPE realtime_throughput_messages_per_second gauge\n";
        $output .= "realtime_throughput_messages_per_second " . ($metrics['global']['messages_per_second'] ?? 0) . "\n";
        
        $output .= "# HELP realtime_error_rate Error rate percentage\n";
        $output .= "# TYPE realtime_error_rate gauge\n";
        $output .= "realtime_error_rate " . ($metrics['global']['error_rate'] ?? 0) . "\n";
        
        // Protocol-specific metrics
        foreach ($metrics['protocols'] ?? [] as $protocol => $protocolMetrics) {
            $output .= "realtime_protocol_connections{protocol=\"{$protocol}\"} " . ($protocolMetrics['connections'] ?? 0) . "\n";
            $output .= "realtime_protocol_latency{protocol=\"{$protocol}\"} " . (($protocolMetrics['average_latency'] ?? 0) / 1000) . "\n";
        }
        
        return $output;
    }
    
    /**
     * Export metrics in InfluxDB line protocol format
     */
    private function exportInfluxDBFormat(array $metrics): string
    {
        $timestamp = time() * 1000000000; // nanoseconds
        $lines = [];
        
        // Global metrics
        $globalTags = "host=" . gethostname();
        $globalFields = [];
        
        foreach ($metrics['global'] ?? [] as $key => $value) {
            if (is_numeric($value)) {
                $globalFields[] = "{$key}={$value}";
            }
        }
        
        if (!empty($globalFields)) {
            $lines[] = "realtime_global,{$globalTags} " . implode(',', $globalFields) . " {$timestamp}";
        }
        
        // Protocol metrics
        foreach ($metrics['protocols'] ?? [] as $protocol => $protocolMetrics) {
            $protocolTags = "{$globalTags},protocol={$protocol}";
            $protocolFields = [];
            
            foreach ($protocolMetrics as $key => $value) {
                if (is_numeric($value)) {
                    $protocolFields[] = "{$key}={$value}";
                }
            }
            
            if (!empty($protocolFields)) {
                $lines[] = "realtime_protocol,{$protocolTags} " . implode(',', $protocolFields) . " {$timestamp}";
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Get system information
     */
    private function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'hostname' => gethostname(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'timezone' => date_default_timezone_get(),
            'extensions' => [
                'amphp' => extension_loaded('amphp'),
                'pcntl' => extension_loaded('pcntl'),
                'sockets' => extension_loaded('sockets'),
                'openssl' => extension_loaded('openssl')
            ]
        ];
    }
    
    /**
     * Get protocol states
     */
    private function getProtocolStates(): array
    {
        $states = [];
        
        foreach ($this->protocolMonitors as $protocolName => $monitor) {
            $states[$protocolName] = [
                'active' => $monitor->isActive() ?? false,
                'connections' => $this->protocolMetrics[$protocolName]['connections'] ?? 0,
                'last_activity' => $this->protocolMetrics[$protocolName]['last_activity'] ?? 0,
                'status' => $this->determineProtocolStatus($protocolName)
            ];
        }
        
        return $states;
    }
    
    /**
     * Get CPU usage percentage
     */
    private function getCPUUsage(): float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return round($load[0] * 100 / 4, 2); // Assuming 4 cores
        }
        
        return 0.0;
    }
    
    /**
     * Get memory usage percentage
     */
    private function getMemoryUsage(): float
    {
        $memoryUsed = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        
        if ($memoryLimit > 0) {
            return round(($memoryUsed / $memoryLimit) * 100, 2);
        }
        
        return 0.0;
    }
    
    // Additional helper methods
    private function getDiskUsage(): float { return 0.0; }
    private function getNetworkStats(): array { return []; }
    private function getCompressionMetrics(): array { return []; }
    private function updateMovingAverages(): void {}
    private function updatePercentiles(): void {}
    private function updateProtocolRankings(): void {}
    private function updateTrendAnalysis(): void {}
    private function sendAlertNotification(array $alert): \Generator { return yield; }
    private function attemptAutoRemediation(array $alert): \Generator { return yield; }
    private function recordAlert(array $alert): void {}
    private function cleanupOldPerformanceData(int $cutoffTime): void {}
    private function calculateProtocolScore(string $protocol, array $metrics): float { return 85.0; }
    private function determineProtocolStatus(string $protocol): string { return 'active'; }
    private function parseMemoryLimit(string $limit): int {
        $limit = trim($limit);
        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);
        
        switch ($unit) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
            default: return (int) $limit;
        }
    }
}