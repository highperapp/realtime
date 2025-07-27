<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\ABTesting;

use Psr\Log\LoggerInterface;

/**
 * A/B Testing for Protocol Configurations
 * 
 * Enables data-driven protocol optimization through controlled experiments:
 * - Multi-variant protocol testing (HTTP/2 vs HTTP/3, WebSocket vs WebTransport)
 * - Performance metric comparison across protocol variants
 * - Statistical significance testing for protocol performance
 * - Gradual rollout and automatic winner selection
 * - Real-time experiment monitoring and control
 */
class ProtocolABTester
{
    private LoggerInterface $logger;
    private array $config;
    private ExperimentManager $experimentManager;
    private VariantSelector $variantSelector;
    private MetricsCollector $metricsCollector;
    private StatisticalAnalyzer $analyzer;
    
    private array $activeExperiments = [];
    private array $experimentResults = [];
    private array $metrics = [];
    private bool $isRunning = false;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->initializeComponents();
        $this->initializeMetrics();
    }

    /**
     * Initialize A/B testing components
     */
    private function initializeComponents(): void
    {
        $this->experimentManager = new ExperimentManager($this->logger, $this->config);
        $this->variantSelector = new VariantSelector($this->logger, $this->config);
        $this->metricsCollector = new MetricsCollector($this->logger, $this->config);
        $this->analyzer = new StatisticalAnalyzer($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'active_experiments' => 0,
            'completed_experiments' => 0,
            'participants' => 0,
            'statistical_significance_achieved' => 0,
            'winner_protocols' => [],
            'performance_improvements' => []
        ];
    }

    /**
     * Create new protocol experiment
     */
    public function createExperiment(string $name, array $variants, array $config = []): string
    {
        $experiment = new ProtocolExperiment(
            $this->generateExperimentId(),
            $name,
            $variants,
            array_merge($this->config['experiment_defaults'], $config),
            $this->logger
        );

        $experimentId = $experiment->getId();
        $this->activeExperiments[$experimentId] = $experiment;
        $this->metrics['active_experiments']++;

        $this->logger->info('Protocol A/B experiment created', [
            'experiment_id' => $experimentId,
            'name' => $name,
            'variants' => array_keys($variants)
        ]);

        return $experimentId;
    }

    /**
     * Select protocol variant for user
     */
    public function selectVariant(string $experimentId, string $userId, array $context = []): ?array
    {
        if (!isset($this->activeExperiments[$experimentId])) {
            return null;
        }

        $experiment = $this->activeExperiments[$experimentId];
        
        if (!$experiment->isActive()) {
            return null;
        }

        $variant = $this->variantSelector->select($experiment, $userId, $context);
        
        if ($variant) {
            $experiment->addParticipant($userId, $variant['name']);
            $this->metrics['participants']++;
        }

        return $variant;
    }

    /**
     * Record performance metrics for variant
     */
    public function recordMetrics(string $experimentId, string $userId, string $variantName, array $metrics): void
    {
        if (!isset($this->activeExperiments[$experimentId])) {
            return;
        }

        $experiment = $this->activeExperiments[$experimentId];
        $experiment->recordMetrics($userId, $variantName, $metrics);

        $this->metricsCollector->record($experimentId, $variantName, $metrics);
    }

    /**
     * Analyze experiment results
     */
    public function analyzeExperiment(string $experimentId): \Generator
    {
        if (!isset($this->activeExperiments[$experimentId])) {
            return yield null;
        }

        $experiment = $this->activeExperiments[$experimentId];
        $analysisResult = yield $this->analyzer->analyze($experiment);
        
        if ($analysisResult['statistical_significance']) {
            $this->metrics['statistical_significance_achieved']++;
            
            // Auto-conclude if configured
            if ($this->config['auto_conclude_experiments']) {
                yield $this->concludeExperiment($experimentId, $analysisResult);
            }
        }

        return yield $analysisResult;
    }

    /**
     * Get experiment status
     */
    public function getExperimentStatus(string $experimentId): array
    {
        if (!isset($this->activeExperiments[$experimentId])) {
            return ['status' => 'not_found'];
        }

        $experiment = $this->activeExperiments[$experimentId];
        
        return [
            'id' => $experimentId,
            'name' => $experiment->getName(),
            'status' => $experiment->getStatus(),
            'participants' => $experiment->getParticipantCount(),
            'variants' => $experiment->getVariantDistribution(),
            'metrics' => $experiment->getMetricsSummary(),
            'duration' => $experiment->getDuration(),
            'statistical_power' => $experiment->getStatisticalPower()
        ];
    }

    /**
     * Start A/B testing service
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting Protocol A/B Testing service');

        yield $this->experimentManager->start();
        yield $this->variantSelector->start();
        yield $this->metricsCollector->start();
        yield $this->analyzer->start();

        $this->isRunning = true;

        $this->logger->info('Protocol A/B Testing service started');
    }

    /**
     * Stop service
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return yield;
        }

        $this->logger->info('Stopping Protocol A/B Testing service');
        $this->isRunning = false;
        $this->logger->info('Protocol A/B Testing service stopped');
        return yield;
    }

    /**
     * Get metrics
     */
    public function getMetrics(): array
    {
        $this->metrics['active_experiments'] = count($this->activeExperiments);
        return $this->metrics;
    }

    private function generateExperimentId(): string
    {
        return 'exp-' . bin2hex(random_bytes(6));
    }

    private function concludeExperiment(string $experimentId, array $analysisResult): \Generator
    {
        $experiment = $this->activeExperiments[$experimentId];
        $experiment->conclude($analysisResult['winner']);
        
        $this->experimentResults[$experimentId] = $analysisResult;
        unset($this->activeExperiments[$experimentId]);
        
        $this->metrics['completed_experiments']++;
        $this->metrics['winner_protocols'][] = $analysisResult['winner'];
        
        return yield;
    }

    private function getDefaultConfig(): array
    {
        return [
            'auto_conclude_experiments' => true,
            'min_participants_per_variant' => 100,
            'significance_level' => 0.05,
            'statistical_power' => 0.8,
            'experiment_defaults' => [
                'duration_days' => 7,
                'traffic_allocation' => 0.1,
                'metrics_tracked' => ['latency', 'error_rate', 'throughput']
            ]
        ];
    }
}