<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Optimization;

use Psr\Log\LoggerInterface;

/**
 * Latency Measurer
 *
 * Handles low-level latency measurement operations
 */
class LatencyMeasurer
{
    private LoggerInterface $logger;
    private array $config;
    private bool $isRunning = false;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function start(): \Generator
    {
        $this->isRunning = true;
        $this->logger->debug('Latency measurer started');
        return yield;
    }

    public function stop(): \Generator
    {
        $this->isRunning = false;
        $this->logger->debug('Latency measurer stopped');
        return yield;
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }
}