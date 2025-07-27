<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Optimization;

use Psr\Log\LoggerInterface;

/**
 * SLA Monitor
 *
 * Monitors latency SLA compliance and alerts on violations
 */
class SLAMonitor
{
    private LoggerInterface $logger;
    private array $config;
    private bool $isRunning = false;
    private array $slaThresholds = [];
    private array $violations = [];

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'p95_threshold' => 200,
            'p99_threshold' => 500,
            'availability_threshold' => 99.9,
            'check_interval' => 60000 // 1 minute
        ], $config);
        
        $this->initializeSLAThresholds();
    }

    public function start(): \Generator
    {
        $this->isRunning = true;
        $this->logger->info('SLA monitor started');
        return yield;
    }

    public function stop(): \Generator
    {
        $this->isRunning = false;
        $this->logger->info('SLA monitor stopped');
        return yield;
    }

    public function checkCompliance(): array
    {
        $currentTime = microtime(true);
        $timeWindow = 3600; // 1 hour window
        
        // Get recent violations in the time window
        $recentViolations = array_filter(
            $this->violations,
            fn($v) => $v['timestamp'] > ($currentTime - $timeWindow)
        );

        $totalChecks = max(1, intval($timeWindow / ($this->config['check_interval'] / 1000)));
        $violationCount = count($recentViolations);
        $compliance = (($totalChecks - $violationCount) / $totalChecks) * 100;

        $isCompliant = $compliance >= $this->config['availability_threshold'];

        $result = [
            'compliant' => $isCompliant,
            'compliance_percentage' => $compliance,
            'threshold' => $this->config['availability_threshold'],
            'violation_count' => $violationCount,
            'total_checks' => $totalChecks,
            'time_window' => $timeWindow,
            'recent_violations' => $recentViolations,
            'status' => $isCompliant ? 'ok' : 'violation'
        ];

        if (!$isCompliant) {
            $this->logger->warning('SLA compliance violation detected', $result);
        }

        return $result;
    }

    public function recordViolation(string $type, array $details): void
    {
        $violation = [
            'type' => $type,
            'details' => $details,
            'timestamp' => microtime(true),
            'severity' => $this->calculateSeverity($details)
        ];

        $this->violations[] = $violation;

        // Keep only recent violations (last 24 hours)
        $cutoff = microtime(true) - 86400;
        $this->violations = array_filter(
            $this->violations,
            fn($v) => $v['timestamp'] > $cutoff
        );

        $this->logger->warning('SLA violation recorded', $violation);
    }

    private function initializeSLAThresholds(): void
    {
        $this->slaThresholds = [
            'p95_latency' => $this->config['p95_threshold'],
            'p99_latency' => $this->config['p99_threshold'],
            'availability' => $this->config['availability_threshold'],
            'error_rate' => 1.0 // 1% max error rate
        ];
    }

    private function calculateSeverity(array $details): string
    {
        if (isset($details['latency'])) {
            $latency = $details['latency'];
            if ($latency > 1000) return 'critical';
            if ($latency > 500) return 'high';
            if ($latency > 200) return 'medium';
            return 'low';
        }

        return 'medium';
    }

    public function getSLAThresholds(): array
    {
        return $this->slaThresholds;
    }

    public function getViolationHistory(int $hours = 24): array
    {
        $cutoff = microtime(true) - ($hours * 3600);
        
        return array_filter(
            $this->violations,
            fn($v) => $v['timestamp'] > $cutoff
        );
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }
}