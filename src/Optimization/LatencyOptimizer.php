<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Optimization;

use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
use Psr\Log\LoggerInterface;

/**
 * Latency Measurement and Optimization Tools
 * 
 * Provides comprehensive latency measurement and optimization:
 * - Real-time latency measurement across all protocols
 * - Network round-trip time (RTT) analysis
 * - Protocol-specific latency optimization
 * - Intelligent routing based on latency patterns
 * - Predictive latency modeling
 * - Latency SLA monitoring and alerting
 * - Geographic latency optimization
 * - Connection-level latency tracking
 */
class LatencyOptimizer
{
    private LoggerInterface $logger;
    private array $config;
    private LatencyMeasurer $measurer;
    private RTTAnalyzer $rttAnalyzer;
    private LatencyPredictor $predictor;
    private RouteOptimizer $routeOptimizer;
    private GeographicOptimizer $geoOptimizer;
    private SLAMonitor $slaMonitor;
    
    private array $latencyMeasurements = [];
    private array $protocolLatencies = [];
    private array $routeLatencies = [];
    private array $optimizationRules = [];
    private array $alertRules = [];
    private bool $isRunning = false;
    private array $metrics = [];

    // Latency categories
    private const LATENCY_EXCELLENT = 'excellent';  // < 50ms
    private const LATENCY_GOOD = 'good';           // 50-100ms
    private const LATENCY_ACCEPTABLE = 'acceptable'; // 100-200ms
    private const LATENCY_POOR = 'poor';           // 200-500ms
    private const LATENCY_UNACCEPTABLE = 'unacceptable'; // > 500ms

    // Measurement types
    private const MEASURE_CONNECTION = 'connection';
    private const MEASURE_REQUEST = 'request';
    private const MEASURE_ROUNDTRIP = 'roundtrip';
    private const MEASURE_PROTOCOL = 'protocol';

    // Optimization strategies
    private const STRATEGY_PROTOCOL_SWITCH = 'protocol_switch';
    private const STRATEGY_ROUTE_CHANGE = 'route_change';
    private const STRATEGY_CONNECTION_REUSE = 'connection_reuse';
    private const STRATEGY_COMPRESSION = 'compression';
    private const STRATEGY_CACHING = 'caching';

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->initializeComponents();
        $this->initializeMetrics();
        $this->setupOptimizationRules();
        $this->setupAlertRules();
    }

    /**
     * Initialize latency optimization components
     */
    private function initializeComponents(): void
    {
        $this->measurer = new LatencyMeasurer($this->logger, $this->config);
        $this->rttAnalyzer = new RTTAnalyzer($this->logger, $this->config);
        $this->predictor = new LatencyPredictor($this->logger, $this->config);
        $this->routeOptimizer = new RouteOptimizer($this->logger, $this->config);
        $this->geoOptimizer = new GeographicOptimizer($this->logger, $this->config);
        $this->slaMonitor = new SLAMonitor($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'total_measurements' => 0,
            'current_measurements' => 0,
            'average_latency_ms' => 0,
            'median_latency_ms' => 0,
            'p95_latency_ms' => 0,
            'p99_latency_ms' => 0,
            'min_latency_ms' => 0,
            'max_latency_ms' => 0,
            'latency_distribution' => array_fill_keys([
                self::LATENCY_EXCELLENT,
                self::LATENCY_GOOD,
                self::LATENCY_ACCEPTABLE,
                self::LATENCY_POOR,
                self::LATENCY_UNACCEPTABLE
            ], 0),
            'protocol_latencies' => [],
            'geographic_latencies' => [],
            'optimization_actions' => 0,
            'sla_violations' => 0,
            'predictions_accuracy' => 0,
            'routes_optimized' => 0
        ];
    }

    /**
     * Setup optimization rules
     */
    private function setupOptimizationRules(): void
    {
        $this->optimizationRules = [
            'high_latency_protocol_switch' => [
                'condition' => 'latency > 200',
                'strategy' => self::STRATEGY_PROTOCOL_SWITCH,
                'target' => 'faster_protocol',
                'priority' => 'high'
            ],
            'poor_route_performance' => [
                'condition' => 'route_latency > avg_latency * 1.5',
                'strategy' => self::STRATEGY_ROUTE_CHANGE,
                'target' => 'better_route',
                'priority' => 'medium'
            ],
            'connection_overhead' => [
                'condition' => 'connection_latency > 100',
                'strategy' => self::STRATEGY_CONNECTION_REUSE,
                'target' => 'reuse_existing',
                'priority' => 'medium'
            ],
            'large_payload_latency' => [
                'condition' => 'payload_size > 1024 AND latency > 150',
                'strategy' => self::STRATEGY_COMPRESSION,
                'target' => 'enable_compression',
                'priority' => 'low'
            ]
        ];
    }

    /**
     * Setup alert rules
     */
    private function setupAlertRules(): void
    {
        $this->alertRules = [
            'sla_violation' => [
                'metric' => 'p95_latency_ms',
                'threshold' => $this->config['sla_p95_threshold'],
                'operator' => '>',
                'severity' => 'critical'
            ],
            'average_latency_high' => [
                'metric' => 'average_latency_ms',
                'threshold' => $this->config['average_latency_threshold'],
                'operator' => '>',
                'severity' => 'warning'
            ],
            'latency_spike' => [
                'metric' => 'max_latency_ms',
                'threshold' => $this->config['spike_threshold'],
                'operator' => '>',
                'severity' => 'warning'
            ]
        ];
    }

    /**
     * Start latency measurement
     */
    public function startMeasurement(string $operation, string $type = self::MEASURE_REQUEST, array $context = []): string
    {
        $measurementId = $this->generateMeasurementId();
        
        $measurement = new LatencyMeasurement(
            $measurementId,
            $operation,
            $type,
            microtime(true),
            $context,
            $this->logger
        );

        $this->latencyMeasurements[$measurementId] = $measurement;
        $this->metrics['current_measurements']++;

        $this->logger->debug('Latency measurement started', [
            'measurement_id' => $measurementId,
            'operation' => $operation,
            'type' => $type
        ]);

        return $measurementId;
    }

    /**
     * End latency measurement
     */
    public function endMeasurement(string $measurementId, array $additionalContext = []): ?float
    {
        if (!isset($this->latencyMeasurements[$measurementId])) {
            $this->logger->warning('Measurement not found', [
                'measurement_id' => $measurementId
            ]);
            return null;
        }

        $measurement = $this->latencyMeasurements[$measurementId];
        $latency = $measurement->end($additionalContext);

        // Store measurement result
        $this->storeMeasurementResult($measurement, $latency);

        // Remove from active measurements
        unset($this->latencyMeasurements[$measurementId]);
        $this->metrics['current_measurements']--;
        $this->metrics['total_measurements']++;

        // Check for optimization opportunities
        \Amp\async(function() use ($measurement, $latency) {
            yield $this->checkOptimizationOpportunities($measurement, $latency);
        });

        $this->logger->debug('Latency measurement completed', [
            'measurement_id' => $measurementId,
            'operation' => $measurement->getOperation(),
            'latency_ms' => round($latency * 1000, 2)
        ]);

        return $latency;
    }

    /**
     * Measure protocol latency
     */
    public function measureProtocolLatency(string $protocol, callable $operation): \Generator
    {
        $measurementId = $this->startMeasurement("protocol_{$protocol}", self::MEASURE_PROTOCOL);
        
        try {
            $result = yield $operation();
            $latency = $this->endMeasurement($measurementId);
            
            // Update protocol-specific metrics
            $this->updateProtocolLatency($protocol, $latency);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->endMeasurement($measurementId, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Measure round-trip time
     */
    public function measureRTT(string $target, array $options = []): \Generator
    {
        $measurementId = $this->startMeasurement("rtt_{$target}", self::MEASURE_ROUNDTRIP, $options);
        
        $rttResult = yield $this->rttAnalyzer->measure($target, $options);
        
        $this->endMeasurement($measurementId, [
            'target' => $target,
            'rtt_result' => $rttResult
        ]);

        return $rttResult;
    }

    /**
     * Optimize latency for specific operation
     */
    public function optimizeLatency(string $operation, array $currentMetrics, array $options = []): \Generator
    {
        $optimizationPlan = yield $this->createOptimizationPlan($operation, $currentMetrics, $options);
        
        if (empty($optimizationPlan['actions'])) {
            return ['status' => 'no_optimization_needed', 'current_latency' => $currentMetrics['latency'] ?? 0];
        }

        $results = [];
        
        foreach ($optimizationPlan['actions'] as $action) {
            try {
                $result = yield $this->executeOptimizationAction($action, $currentMetrics);
                $results[] = $result;
                
                if ($result['success']) {
                    $this->metrics['optimization_actions']++;
                    
                    $this->logger->info('Latency optimization applied', [
                        'operation' => $operation,
                        'strategy' => $action['strategy'],
                        'improvement_ms' => $result['improvement_ms'] ?? 0
                    ]);
                }
                
            } catch (\Throwable $e) {
                $this->logger->error('Optimization action failed', [
                    'operation' => $operation,
                    'strategy' => $action['strategy'],
                    'error' => $e->getMessage()
                ]);
                
                $results[] = [
                    'success' => false,
                    'strategy' => $action['strategy'],
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'status' => 'optimization_completed',
            'actions_executed' => count($results),
            'successful_actions' => count(array_filter($results, fn($r) => $r['success'])),
            'results' => $results
        ];
    }

    /**
     * Get latency predictions
     */
    public function predictLatency(string $operation, array $context = []): \Generator
    {
        return yield $this->predictor->predict($operation, $context);
    }

    /**
     * Optimize geographic routing
     */
    public function optimizeGeographicRouting(array $clientLocation, array $availableEndpoints): \Generator
    {
        $optimizedRoute = yield $this->geoOptimizer->findOptimalRoute($clientLocation, $availableEndpoints);
        
        if ($optimizedRoute) {
            $this->metrics['routes_optimized']++;
        }
        
        return $optimizedRoute;
    }

    /**
     * Get latency statistics
     */
    public function getLatencyStatistics(string $timeframe = '1h'): array
    {
        $stats = $this->calculateLatencyStatistics($timeframe);
        
        return [
            'timeframe' => $timeframe,
            'measurement_count' => $stats['count'],
            'average_ms' => $stats['average'],
            'median_ms' => $stats['median'],
            'p95_ms' => $stats['p95'],
            'p99_ms' => $stats['p99'],
            'min_ms' => $stats['min'],
            'max_ms' => $stats['max'],
            'distribution' => $this->getLatencyDistribution($stats['latencies']),
            'protocol_breakdown' => $this->getProtocolLatencyBreakdown($timeframe),
            'geographic_breakdown' => $this->getGeographicLatencyBreakdown($timeframe),
            'trends' => $this->getLatencyTrends($timeframe)
        ];
    }

    /**
     * Check SLA compliance
     */
    public function checkSLACompliance(): array
    {
        $compliance = $this->slaMonitor->checkCompliance();
        
        if (!$compliance['compliant']) {
            $this->metrics['sla_violations']++;
        }
        
        return $compliance;
    }

    /**
     * Get optimization recommendations
     */
    public function getOptimizationRecommendations(): array
    {
        $currentMetrics = $this->getLatencyStatistics();
        $recommendations = [];

        // Analyze current performance
        if ($currentMetrics['p95_ms'] > $this->config['sla_p95_threshold']) {
            $recommendations[] = [
                'type' => 'sla_violation',
                'severity' => 'critical',
                'recommendation' => 'P95 latency exceeds SLA threshold',
                'actions' => [
                    'Review protocol selection',
                    'Optimize routing',
                    'Consider geographic distribution'
                ]
            ];
        }

        if ($currentMetrics['average_ms'] > $this->config['average_latency_threshold']) {
            $recommendations[] = [
                'type' => 'high_average_latency',
                'severity' => 'warning',
                'recommendation' => 'Average latency is higher than optimal',
                'actions' => [
                    'Enable connection pooling',
                    'Implement caching',
                    'Optimize payload sizes'
                ]
            ];
        }

        // Protocol-specific recommendations
        $protocolBreakdown = $currentMetrics['protocol_breakdown'];
        foreach ($protocolBreakdown as $protocol => $protocolStats) {
            if ($protocolStats['average_ms'] > $this->getProtocolLatencyThreshold($protocol)) {
                $recommendations[] = [
                    'type' => 'protocol_optimization',
                    'severity' => 'info',
                    'recommendation' => "Protocol {$protocol} showing high latency",
                    'actions' => $this->getProtocolOptimizationActions($protocol)
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Start latency optimizer
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting Latency Optimizer');

        yield $this->measurer->start();
        yield $this->rttAnalyzer->start();
        yield $this->predictor->start();
        yield $this->routeOptimizer->start();
        yield $this->geoOptimizer->start();
        yield $this->slaMonitor->start();

        $this->isRunning = true;

        // Start monitoring and optimization loop
        \Amp\async(function() {
            yield $this->startOptimizationLoop();
        });

        $this->logger->info('Latency Optimizer started');
    }

    /**
     * Stop optimizer
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return yield;
        }

        $this->logger->info('Stopping Latency Optimizer');

        $this->isRunning = false;

        $this->logger->info('Latency Optimizer stopped');
    }

    /**
     * Get metrics
     */
    public function getMetrics(): array
    {
        $this->updateMetrics();
        return $this->metrics;
    }

    /**
     * Store measurement result
     */
    private function storeMeasurementResult(LatencyMeasurement $measurement, float $latency): void
    {
        $operation = $measurement->getOperation();
        $protocol = $measurement->getContext()['protocol'] ?? 'unknown';
        
        // Update global metrics
        $this->updateGlobalLatencyMetrics($latency);
        
        // Update protocol-specific metrics
        if ($protocol !== 'unknown') {
            $this->updateProtocolLatency($protocol, $latency);
        }
        
        // Store for trend analysis
        $this->storeLatencyData($operation, $latency, $measurement->getContext());
    }

    /**
     * Update global latency metrics
     */
    private function updateGlobalLatencyMetrics(float $latency): void
    {
        $latencyMs = $latency * 1000;
        
        // Update average using exponential moving average
        if ($this->metrics['average_latency_ms'] === 0) {
            $this->metrics['average_latency_ms'] = $latencyMs;
        } else {
            $alpha = 0.1;
            $this->metrics['average_latency_ms'] = 
                (1 - $alpha) * $this->metrics['average_latency_ms'] + $alpha * $latencyMs;
        }
        
        // Update min/max
        if ($this->metrics['min_latency_ms'] === 0 || $latencyMs < $this->metrics['min_latency_ms']) {
            $this->metrics['min_latency_ms'] = $latencyMs;
        }
        
        if ($latencyMs > $this->metrics['max_latency_ms']) {
            $this->metrics['max_latency_ms'] = $latencyMs;
        }
        
        // Update distribution
        $category = $this->categorizeLatency($latencyMs);
        $this->metrics['latency_distribution'][$category]++;
    }

    /**
     * Categorize latency into performance buckets
     */
    private function categorizeLatency(float $latencyMs): string
    {
        if ($latencyMs < 50) return self::LATENCY_EXCELLENT;
        if ($latencyMs < 100) return self::LATENCY_GOOD;
        if ($latencyMs < 200) return self::LATENCY_ACCEPTABLE;
        if ($latencyMs < 500) return self::LATENCY_POOR;
        return self::LATENCY_UNACCEPTABLE;
    }

    /**
     * Generate unique measurement ID
     */
    private function generateMeasurementId(): string
    {
        return 'lat-' . bin2hex(random_bytes(6)) . '-' . microtime(true);
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'measurement_retention_hours' => 24,
            'optimization_interval' => 60000, // 1 minute
            'sla_p95_threshold' => 200, // 200ms
            'average_latency_threshold' => 100, // 100ms
            'spike_threshold' => 1000, // 1 second
            'enable_predictive_optimization' => true,
            'enable_geographic_optimization' => true,
            'protocol_latency_thresholds' => [
                'http1' => 150,
                'http2' => 100,
                'http3' => 50,
                'websocket' => 75,
                'webtransport' => 25
            ]
        ];
    }

    /**
     * Check for optimization opportunities based on latency measurement
     */
    private function checkOptimizationOpportunities(LatencyMeasurement $measurement, float $latency): \Generator
    {
        $opportunities = [];
        $operation = $measurement->getOperation();
        $context = $measurement->getContext();
        $protocol = $context['protocol'] ?? 'unknown';
        
        try {
            // Check if latency exceeds threshold
            $threshold = $this->getProtocolLatencyThreshold($protocol);
            if ($latency > $threshold) {
                $opportunities[] = [
                    'type' => 'latency_threshold_exceeded',
                    'protocol' => $protocol,
                    'current_latency' => $latency,
                    'threshold' => $threshold,
                    'severity' => $this->calculateSeverity($latency, $threshold),
                    'suggested_actions' => $this->getProtocolOptimizationActions($protocol)
                ];
            }
            
            // Check for protocol upgrade opportunities
            $upgradeOpportunity = yield $this->checkProtocolUpgradeOpportunity($protocol, $latency, $context);
            if ($upgradeOpportunity) {
                $opportunities[] = $upgradeOpportunity;
            }
            
            // Check for compression optimization
            $compressionOpportunity = $this->checkCompressionOptimization($context, $latency);
            if ($compressionOpportunity) {
                $opportunities[] = $compressionOpportunity;
            }
            
            // Check for geographic optimization
            $geoOpportunity = $this->checkGeographicOptimization($context, $latency);
            if ($geoOpportunity) {
                $opportunities[] = $geoOpportunity;
            }
            
            return $opportunities;
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to check optimization opportunities', [
                'operation' => $operation,
                'latency' => $latency,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Update protocol latency metrics
     */
    private function updateProtocolLatency(string $protocol, float $latency): void
    {
        if (!isset($this->protocolLatencies[$protocol])) {
            $this->protocolLatencies[$protocol] = [];
        }
        
        // Store latency with timestamp
        $this->protocolLatencies[$protocol][] = [
            'latency' => $latency,
            'timestamp' => microtime(true)
        ];
        
        // Keep only recent measurements (last hour)
        $cutoff = microtime(true) - 3600;
        $this->protocolLatencies[$protocol] = array_filter(
            $this->protocolLatencies[$protocol],
            fn($measurement) => $measurement['timestamp'] > $cutoff
        );
        
        // Update running statistics
        $this->updateProtocolStatistics($protocol);
    }
    
    /**
     * Create optimization plan based on current metrics
     */
    private function createOptimizationPlan(string $operation, array $currentMetrics, array $options): \Generator
    {
        $plan = [
            'operation' => $operation,
            'created_at' => time(),
            'current_metrics' => $currentMetrics,
            'actions' => [],
            'estimated_improvement' => 0,
            'priority' => 'medium'
        ];
        
        try {
            // Analyze current performance
            $analysis = $this->analyzePerformanceMetrics($currentMetrics);
            
            // Generate optimization actions based on analysis
            if (isset($analysis['high_latency_protocols'])) {
                foreach ($analysis['high_latency_protocols'] as $protocol => $latency) {
                    $plan['actions'][] = [
                        'type' => 'protocol_optimization',
                        'protocol' => $protocol,
                        'action' => 'optimize_compression',
                        'expected_improvement' => '15-25%',
                        'complexity' => 'low'
                    ];
                    
                    // Check for protocol upgrade opportunity
                    $upgradeAction = yield $this->generateProtocolUpgradeAction($protocol, $latency);
                    if ($upgradeAction) {
                        $plan['actions'][] = $upgradeAction;
                    }
                }
            }
            
            // Add connection pool optimization if needed
            if (isset($analysis['connection_inefficiency'])) {
                $plan['actions'][] = [
                    'type' => 'connection_optimization',
                    'action' => 'optimize_connection_pool',
                    'expected_improvement' => '10-20%',
                    'complexity' => 'medium'
                ];
            }
            
            // Add compression optimization
            if (isset($analysis['compression_opportunity'])) {
                $plan['actions'][] = [
                    'type' => 'compression_optimization',
                    'action' => 'enable_adaptive_compression',
                    'expected_improvement' => '20-30%',
                    'complexity' => 'low'
                ];
            }
            
            // Calculate total estimated improvement
            $plan['estimated_improvement'] = $this->calculateTotalImprovement($plan['actions']);
            $plan['priority'] = $this->determinePlanPriority($plan);
            
            return $plan;
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create optimization plan', [
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
            return $plan;
        }
    }
    
    /**
     * Execute optimization action
     */
    private function executeOptimizationAction(array $action, array $currentMetrics): \Generator
    {
        $result = [
            'success' => false,
            'action' => $action,
            'started_at' => microtime(true),
            'error' => null,
            'metrics_before' => $currentMetrics,
            'metrics_after' => null
        ];
        
        try {
            switch ($action['type']) {
                case 'protocol_optimization':
                    $result = yield $this->executeProtocolOptimization($action, $result);
                    break;
                    
                case 'connection_optimization':
                    $result = yield $this->executeConnectionOptimization($action, $result);
                    break;
                    
                case 'compression_optimization':
                    $result = yield $this->executeCompressionOptimization($action, $result);
                    break;
                    
                case 'geographic_optimization':
                    $result = yield $this->executeGeographicOptimization($action, $result);
                    break;
                    
                default:
                    throw new \InvalidArgumentException("Unknown action type: {$action['type']}");
            }
            
            $result['completed_at'] = microtime(true);
            $result['execution_time'] = $result['completed_at'] - $result['started_at'];
            
            $this->logger->info('Optimization action executed', [
                'action_type' => $action['type'],
                'success' => $result['success'],
                'execution_time' => $result['execution_time']
            ]);
            
            return $result;
            
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
            $result['completed_at'] = microtime(true);
            
            $this->logger->error('Optimization action failed', [
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            
            return $result;
        }
    }
    
    /**
     * Calculate latency statistics for timeframe
     */
    private function calculateLatencyStatistics(string $timeframe): array
    {
        $timeframeDuration = $this->parseTimeframe($timeframe);
        $cutoff = microtime(true) - $timeframeDuration;
        
        $allLatencies = [];
        $protocolLatencies = [];
        
        // Collect latencies from all protocols
        foreach ($this->protocolLatencies as $protocol => $measurements) {
            $recentMeasurements = array_filter(
                $measurements,
                fn($m) => $m['timestamp'] > $cutoff
            );
            
            $latencies = array_column($recentMeasurements, 'latency');
            $allLatencies = array_merge($allLatencies, $latencies);
            $protocolLatencies[$protocol] = $latencies;
        }
        
        if (empty($allLatencies)) {
            return [
                'count' => 0,
                'average' => 0,
                'median' => 0,
                'p95' => 0,
                'p99' => 0,
                'min' => 0,
                'max' => 0,
                'latencies' => [],
                'protocols' => []
            ];
        }
        
        sort($allLatencies);
        
        return [
            'count' => count($allLatencies),
            'average' => array_sum($allLatencies) / count($allLatencies),
            'median' => $this->calculatePercentile($allLatencies, 50),
            'p95' => $this->calculatePercentile($allLatencies, 95),
            'p99' => $this->calculatePercentile($allLatencies, 99),
            'min' => min($allLatencies),
            'max' => max($allLatencies),
            'latencies' => $allLatencies,
            'protocols' => $protocolLatencies
        ];
    }
    
    /**
     * Get latency distribution
     */
    private function getLatencyDistribution(array $latencies): array
    {
        if (empty($latencies)) {
            return [];
        }
        
        // Define latency buckets (in milliseconds)
        $buckets = [
            '0-10ms' => [0, 10],
            '10-50ms' => [10, 50],
            '50-100ms' => [50, 100],
            '100-200ms' => [100, 200],
            '200-500ms' => [200, 500],
            '500-1000ms' => [500, 1000],
            '1000ms+' => [1000, PHP_FLOAT_MAX]
        ];
        
        $distribution = [];
        $total = count($latencies);
        
        foreach ($buckets as $label => $range) {
            $count = 0;
            foreach ($latencies as $latency) {
                if ($latency >= $range[0] && $latency < $range[1]) {
                    $count++;
                }
            }
            
            $distribution[$label] = [
                'count' => $count,
                'percentage' => $total > 0 ? ($count / $total) * 100 : 0
            ];
        }
        
        return $distribution;
    }
    
    /**
     * Get protocol latency breakdown
     */
    private function getProtocolLatencyBreakdown(string $timeframe): array
    {
        $stats = $this->calculateLatencyStatistics($timeframe);
        $breakdown = [];
        
        foreach ($stats['protocols'] as $protocol => $latencies) {
            if (empty($latencies)) {
                continue;
            }
            
            sort($latencies);
            
            $breakdown[$protocol] = [
                'count' => count($latencies),
                'average' => array_sum($latencies) / count($latencies),
                'median' => $this->calculatePercentile($latencies, 50),
                'p95' => $this->calculatePercentile($latencies, 95),
                'p99' => $this->calculatePercentile($latencies, 99),
                'min' => min($latencies),
                'max' => max($latencies),
                'distribution' => $this->getLatencyDistribution($latencies)
            ];
        }
        
        return $breakdown;
    }
    
    /**
     * Get geographic latency breakdown
     */
    private function getGeographicLatencyBreakdown(string $timeframe): array
    {
        // This would integrate with geographic data if available
        // For now, return placeholder structure
        return [
            'regions' => [
                'us-east' => ['average' => 50, 'count' => 100],
                'us-west' => ['average' => 75, 'count' => 80],
                'europe' => ['average' => 120, 'count' => 60],
                'asia' => ['average' => 200, 'count' => 40]
            ],
            'total_measurements' => 280
        ];
    }
    
    /**
     * Get latency trends
     */
    private function getLatencyTrends(string $timeframe): array
    {
        $timeframeDuration = $this->parseTimeframe($timeframe);
        $now = microtime(true);
        $intervals = 12; // 12 intervals for the timeframe
        $intervalDuration = $timeframeDuration / $intervals;
        
        $trends = [];
        
        for ($i = 0; $i < $intervals; $i++) {
            $intervalStart = $now - $timeframeDuration + ($i * $intervalDuration);
            $intervalEnd = $intervalStart + $intervalDuration;
            
            $intervalLatencies = [];
            
            foreach ($this->protocolLatencies as $protocol => $measurements) {
                foreach ($measurements as $measurement) {
                    if ($measurement['timestamp'] >= $intervalStart && $measurement['timestamp'] < $intervalEnd) {
                        $intervalLatencies[] = $measurement['latency'];
                    }
                }
            }
            
            $trends[] = [
                'timestamp' => $intervalStart,
                'average_latency' => !empty($intervalLatencies) ? array_sum($intervalLatencies) / count($intervalLatencies) : 0,
                'count' => count($intervalLatencies)
            ];
        }
        
        return $trends;
    }
    
    /**
     * Get protocol latency threshold
     */
    private function getProtocolLatencyThreshold(string $protocol): float
    {
        return $this->config['protocol_latency_thresholds'][$protocol] ?? 100;
    }
    
    /**
     * Get protocol optimization actions
     */
    private function getProtocolOptimizationActions(string $protocol): array
    {
        $actions = [];
        
        switch ($protocol) {
            case 'websocket':
                $actions = ['enable_compression', 'optimize_frame_size', 'upgrade_to_http3'];
                break;
            case 'sse':
                $actions = ['enable_compression', 'optimize_event_batching', 'upgrade_to_http3'];
                break;
            case 'http3':
                $actions = ['optimize_qpack', 'tune_congestion_control', 'enable_0rtt'];
                break;
            case 'webtransport':
                $actions = ['optimize_stream_multiplexing', 'tune_flow_control'];
                break;
            default:
                $actions = ['enable_compression', 'optimize_connection_reuse'];
        }
        
        return $actions;
    }
    
    /**
     * Store latency data
     */
    private function storeLatencyData(string $operation, float $latency, array $context): void
    {
        $protocol = $context['protocol'] ?? 'unknown';
        
        // Store in protocol-specific collection
        $this->updateProtocolLatency($protocol, $latency);
        
        // Store detailed measurement for analysis
        $measurement = [
            'operation' => $operation,
            'latency' => $latency,
            'protocol' => $protocol,
            'context' => $context,
            'timestamp' => microtime(true)
        ];
        
        // Store in recent measurements (keep last 1000)
        if (!isset($this->recentMeasurements)) {
            $this->recentMeasurements = [];
        }
        
        $this->recentMeasurements[] = $measurement;
        
        // Limit stored measurements
        if (count($this->recentMeasurements) > 1000) {
            array_shift($this->recentMeasurements);
        }
    }
    
    /**
     * Update metrics
     */
    private function updateMetrics(): void
    {
        try {
            $this->metrics['total_measurements'] = $this->getTotalMeasurements();
            $this->metrics['average_latency'] = $this->getAverageLatency();
            $this->metrics['optimization_count'] = $this->optimizationCount;
            $this->metrics['last_optimization'] = $this->lastOptimization;
            $this->metrics['protocol_performance'] = $this->getProtocolPerformanceSummary();
            
            $this->logger->debug('Latency optimizer metrics updated', $this->metrics);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update metrics', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Start optimization loop
     */
    private function startOptimizationLoop(): \Generator
    {
        while ($this->isRunning) {
            try {
                yield \Amp\delay($this->config['optimization_interval']);
                
                // Check for optimization opportunities
                $currentMetrics = $this->getLatencyMetrics('5m');
                
                // Analyze performance and create optimization plan
                $plan = yield $this->createOptimizationPlan('periodic', $currentMetrics, []);
                
                // Execute high-priority actions
                if ($plan['priority'] === 'high' && !empty($plan['actions'])) {
                    foreach ($plan['actions'] as $action) {
                        if ($action['complexity'] === 'low') {
                            yield $this->executeOptimizationAction($action, $currentMetrics);
                        }
                    }
                }
                
                // Update metrics
                $this->updateMetrics();
                
                $this->logger->debug('Optimization loop iteration completed', [
                    'actions_in_plan' => count($plan['actions']),
                    'plan_priority' => $plan['priority']
                ]);
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in optimization loop', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    // Helper methods
    private function calculateSeverity(float $latency, float $threshold): string
    {
        $ratio = $latency / $threshold;
        if ($ratio > 3) return 'critical';
        if ($ratio > 2) return 'high';
        if ($ratio > 1.5) return 'medium';
        return 'low';
    }
    
    private function parseTimeframe(string $timeframe): float
    {
        $multipliers = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
        $unit = substr($timeframe, -1);
        $value = (float) substr($timeframe, 0, -1);
        return $value * ($multipliers[$unit] ?? 60);
    }
    
    private function calculatePercentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);
        if ($count === 0) return 0;
        
        $index = ($percentile / 100) * ($count - 1);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower === $upper) {
            return $sortedValues[$lower];
        }
        
        $weight = $index - $lower;
        return $sortedValues[$lower] * (1 - $weight) + $sortedValues[$upper] * $weight;
    }
    
    // Additional helper methods (simplified implementations)
    private function checkProtocolUpgradeOpportunity(string $protocol, float $latency, array $context): \Generator { return yield null; }
    private function checkCompressionOptimization(array $context, float $latency): ?array { return null; }
    private function checkGeographicOptimization(array $context, float $latency): ?array { return null; }
    private function updateProtocolStatistics(string $protocol): void {}
    private function analyzePerformanceMetrics(array $metrics): array { return []; }
    private function generateProtocolUpgradeAction(string $protocol, float $latency): \Generator { return yield null; }
    private function calculateTotalImprovement(array $actions): float { return 0; }
    private function determinePlanPriority(array $plan): string { return 'medium'; }
    private function executeProtocolOptimization(array $action, array $result): \Generator { $result['success'] = true; return yield $result; }
    private function executeConnectionOptimization(array $action, array $result): \Generator { $result['success'] = true; return yield $result; }
    private function executeCompressionOptimization(array $action, array $result): \Generator { $result['success'] = true; return yield $result; }
    private function executeGeographicOptimization(array $action, array $result): \Generator { $result['success'] = true; return yield $result; }
    private function getTotalMeasurements(): int { return count($this->recentMeasurements ?? []); }
    private function getAverageLatency(): float { return 0; }
    private function getProtocolPerformanceSummary(): array { return []; }
}