<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Optimization;

use Psr\Log\LoggerInterface;

/**
 * Latency Measurement
 *
 * Represents a single latency measurement instance with timing and context
 */
class LatencyMeasurement
{
    private string $id;
    private string $operation;
    private string $type;
    private float $startTime;
    private array $context;
    private LoggerInterface $logger;
    private ?float $endTime = null;
    private ?float $latency = null;
    private array $additionalContext = [];

    public function __construct(
        string $id,
        string $operation,
        string $type,
        float $startTime,
        array $context,
        LoggerInterface $logger
    ) {
        $this->id = $id;
        $this->operation = $operation;
        $this->type = $type;
        $this->startTime = $startTime;
        $this->context = $context;
        $this->logger = $logger;
    }

    /**
     * End the measurement and calculate latency
     */
    public function end(array $additionalContext = []): float
    {
        if ($this->endTime !== null) {
            throw new \RuntimeException("Measurement {$this->id} has already been ended");
        }

        $this->endTime = microtime(true);
        $this->latency = $this->endTime - $this->startTime;
        $this->additionalContext = $additionalContext;

        return $this->latency;
    }

    /**
     * Get measurement ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get operation name
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Get measurement type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get start time
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * Get end time
     */
    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    /**
     * Get measured latency
     */
    public function getLatency(): ?float
    {
        return $this->latency;
    }

    /**
     * Get initial context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get additional context from end()
     */
    public function getAdditionalContext(): array
    {
        return $this->additionalContext;
    }

    /**
     * Get full context (merged)
     */
    public function getFullContext(): array
    {
        return array_merge($this->context, $this->additionalContext);
    }

    /**
     * Check if measurement is completed
     */
    public function isCompleted(): bool
    {
        return $this->endTime !== null;
    }

    /**
     * Get measurement duration so far
     */
    public function getCurrentDuration(): float
    {
        $currentTime = $this->endTime ?? microtime(true);
        return $currentTime - $this->startTime;
    }

    /**
     * Export measurement data
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'type' => $this->type,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'latency' => $this->latency,
            'context' => $this->context,
            'additional_context' => $this->additionalContext,
            'completed' => $this->isCompleted()
        ];
    }
}