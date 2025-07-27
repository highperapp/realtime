<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Optimization;

use Psr\Log\LoggerInterface;

/**
 * Latency Predictor
 *
 * Provides predictive latency modeling based on historical data
 */
class LatencyPredictor
{
    private LoggerInterface $logger;
    private array $config;
    private bool $isRunning = false;
    private array $historicalData = [];

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function start(): \Generator
    {
        $this->isRunning = true;
        $this->logger->debug('Latency predictor started');
        return yield;
    }

    public function stop(): \Generator
    {
        $this->isRunning = false;
        $this->logger->debug('Latency predictor stopped');
        return yield;
    }

    public function predict(string $operation, array $context = []): \Generator
    {
        // Simple prediction based on historical averages
        $baseLatency = $this->getBaseLatency($operation);
        $contextAdjustment = $this->calculateContextAdjustment($context);
        
        $prediction = [
            'operation' => $operation,
            'predicted_latency' => $baseLatency + $contextAdjustment,
            'confidence' => 0.85,
            'factors' => [
                'base_latency' => $baseLatency,
                'context_adjustment' => $contextAdjustment,
                'historical_samples' => count($this->historicalData[$operation] ?? [])
            ],
            'predicted_at' => microtime(true)
        ];

        return yield $prediction;
    }

    private function getBaseLatency(string $operation): float
    {
        $data = $this->historicalData[$operation] ?? [];
        if (empty($data)) {
            return 0.1; // 100ms default
        }

        return array_sum($data) / count($data);
    }

    private function calculateContextAdjustment(array $context): float
    {
        $adjustment = 0.0;

        // Protocol adjustment
        if (isset($context['protocol'])) {
            $protocolFactors = [
                'http3' => -0.02,
                'http2' => 0.01,
                'http1' => 0.05,
                'websocket' => -0.01,
                'webtransport' => -0.03
            ];
            $adjustment += $protocolFactors[$context['protocol']] ?? 0;
        }

        // Payload size adjustment
        if (isset($context['payload_size'])) {
            $adjustment += ($context['payload_size'] / 1024) * 0.001; // 1ms per KB
        }

        return $adjustment;
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }
}