<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\SSE;

use HighPerApp\HighPer\Compression\Contracts\CompressionManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SSE Compression Manager
 * 
 * Manages compression for Server-Sent Events with intelligent algorithm selection,
 * adaptive compression based on content type and client capabilities, and 
 * performance optimization for real-time streaming.
 */
class SSECompressionManager
{
    private LoggerInterface $logger;
    private CompressionManagerInterface $compression;
    private array $config;
    private array $clientCapabilities = [];
    private array $compressionStats = [];
    private array $adaptiveSettings = [];

    public function __construct(
        CompressionManagerInterface $compression,
        LoggerInterface $logger = null,
        array $config = []
    ) {
        $this->compression = $compression;
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeAdaptiveSettings();
        $this->initializeCompressionStats();
    }

    /**
     * Compress SSE event data with intelligent algorithm selection
     */
    public function compressEvent(array $eventData, string $clientId): array
    {
        if (!$this->shouldCompressEvent($eventData, $clientId)) {
            return $eventData;
        }

        $startTime = microtime(true);
        $originalSize = strlen(json_encode($eventData));

        try {
            // Determine optimal compression algorithm
            $algorithm = $this->selectCompressionAlgorithm($eventData, $clientId);
            
            // Prepare compression options
            $options = $this->getCompressionOptions($eventData, $clientId, $algorithm);
            
            // Compress event data
            $compressedData = $this->compressEventData($eventData, $algorithm, $options);
            
            // Update statistics
            $compressionTime = microtime(true) - $startTime;
            $compressedSize = strlen(json_encode($compressedData));
            
            $this->updateCompressionStats($clientId, [
                'original_size' => $originalSize,
                'compressed_size' => $compressedSize,
                'compression_ratio' => $compressedSize / $originalSize,
                'compression_time' => $compressionTime,
                'algorithm' => $algorithm
            ]);

            $this->logger->debug('SSE event compressed', [
                'client_id' => $clientId,
                'algorithm' => $algorithm,
                'original_size' => $originalSize,
                'compressed_size' => $compressedSize,
                'ratio' => round($compressedSize / $originalSize, 3),
                'time_ms' => round($compressionTime * 1000, 2)
            ]);

            return $compressedData;

        } catch (\Throwable $e) {
            $this->logger->warning('SSE event compression failed', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'original_size' => $originalSize
            ]);
            
            // Return original data on compression failure
            return $eventData;
        }
    }

    /**
     * Decompress SSE event data
     */
    public function decompressEvent(array $compressedData, string $clientId): array
    {
        if (!$this->isCompressedEvent($compressedData)) {
            return $compressedData;
        }

        $startTime = microtime(true);

        try {
            $decompressedData = $this->decompressEventData($compressedData);
            
            $decompressionTime = microtime(true) - $startTime;
            
            $this->logger->debug('SSE event decompressed', [
                'client_id' => $clientId,
                'decompression_time_ms' => round($decompressionTime * 1000, 2)
            ]);

            return $decompressedData;

        } catch (\Throwable $e) {
            $this->logger->error('SSE event decompression failed', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            throw new \RuntimeException("Failed to decompress SSE event: " . $e->getMessage());
        }
    }

    /**
     * Register client compression capabilities
     */
    public function registerClientCapabilities(string $clientId, array $capabilities): void
    {
        $this->clientCapabilities[$clientId] = array_merge([
            'accept_encoding' => [],
            'max_compression_level' => 6,
            'prefer_speed_over_ratio' => false,
            'connection_type' => 'unknown',
            'bandwidth_estimate' => null,
            'latency_tolerance' => 'medium'
        ], $capabilities);

        $this->logger->info('Client compression capabilities registered', [
            'client_id' => $clientId,
            'capabilities' => $this->clientCapabilities[$clientId]
        ]);

        // Update adaptive settings for this client
        $this->updateAdaptiveSettings($clientId);
    }

    /**
     * Get compression statistics for monitoring
     */
    public function getCompressionStats(?string $clientId = null): array
    {
        if ($clientId) {
            return $this->compressionStats[$clientId] ?? [];
        }

        // Return aggregated statistics
        return [
            'total_clients' => count($this->compressionStats),
            'total_events_compressed' => array_sum(array_column($this->compressionStats, 'events_compressed')),
            'average_compression_ratio' => $this->calculateAverageCompressionRatio(),
            'average_compression_time' => $this->calculateAverageCompressionTime(),
            'algorithms_used' => $this->getAlgorithmUsageStats(),
            'clients' => $this->compressionStats
        ];
    }

    /**
     * Update configuration for adaptive compression
     */
    public function updateAdaptiveConfiguration(array $config): void
    {
        $this->config = array_merge($this->config, $config);
        $this->initializeAdaptiveSettings();
        
        $this->logger->info('SSE compression configuration updated', [
            'config' => array_keys($config)
        ]);
    }

    /**
     * Enable or disable compression for specific client
     */
    public function setClientCompressionEnabled(string $clientId, bool $enabled): void
    {
        if (!isset($this->clientCapabilities[$clientId])) {
            $this->clientCapabilities[$clientId] = [];
        }
        
        $this->clientCapabilities[$clientId]['compression_enabled'] = $enabled;
        
        $this->logger->info('Client compression setting updated', [
            'client_id' => $clientId,
            'enabled' => $enabled
        ]);
    }

    /**
     * Get recommended compression settings for client
     */
    public function getRecommendedSettings(string $clientId): array
    {
        $capabilities = $this->clientCapabilities[$clientId] ?? [];
        $stats = $this->compressionStats[$clientId] ?? [];
        
        return [
            'algorithm' => $this->selectCompressionAlgorithm([], $clientId),
            'level' => $this->getOptimalCompressionLevel($clientId),
            'should_compress' => $this->shouldCompressForClient($clientId),
            'estimated_savings' => $this->estimateCompressionSavings($clientId),
            'adaptive_settings' => $this->adaptiveSettings[$clientId] ?? []
        ];
    }

    /**
     * Cleanup client data
     */
    public function cleanupClient(string $clientId): void
    {
        unset($this->clientCapabilities[$clientId]);
        unset($this->compressionStats[$clientId]);
        unset($this->adaptiveSettings[$clientId]);
        
        $this->logger->debug('Client compression data cleaned up', [
            'client_id' => $clientId
        ]);
    }

    /**
     * Determine if event should be compressed
     */
    private function shouldCompressEvent(array $eventData, string $clientId): bool
    {
        // Check if compression is enabled globally
        if (!$this->config['enabled']) {
            return false;
        }

        // Check client-specific compression setting
        $clientCapabilities = $this->clientCapabilities[$clientId] ?? [];
        if (isset($clientCapabilities['compression_enabled']) && !$clientCapabilities['compression_enabled']) {
            return false;
        }

        // Check event size threshold
        $eventSize = strlen(json_encode($eventData));
        if ($eventSize < $this->config['min_compression_size']) {
            return false;
        }

        // Check if event type should be compressed
        $eventType = $eventData['event'] ?? 'message';
        if (in_array($eventType, $this->config['excluded_event_types'])) {
            return false;
        }

        // Check adaptive compression rules
        return $this->shouldCompressBasedOnAdaptiveRules($eventData, $clientId);
    }

    /**
     * Select optimal compression algorithm for event and client
     */
    private function selectCompressionAlgorithm(array $eventData, string $clientId): string
    {
        $capabilities = $this->clientCapabilities[$clientId] ?? [];
        $eventSize = strlen(json_encode($eventData));
        
        // Check client's accepted encodings
        $acceptedEncodings = $capabilities['accept_encoding'] ?? ['gzip', 'deflate'];
        
        // Determine optimal algorithm based on event characteristics
        if ($eventSize < 1024) {
            // Small events - prioritize speed
            $algorithm = in_array('lz4', $acceptedEncodings) ? 'lz4' : 'deflate';
        } elseif ($eventSize < 10240) {
            // Medium events - balanced approach
            $algorithm = in_array('gzip', $acceptedEncodings) ? 'gzip' : 'deflate';
        } else {
            // Large events - prioritize compression ratio
            $algorithm = in_array('brotli', $acceptedEncodings) ? 'brotli' : 'gzip';
        }

        // Consider client preferences
        if ($capabilities['prefer_speed_over_ratio'] ?? false) {
            $algorithm = in_array('lz4', $acceptedEncodings) ? 'lz4' : 'deflate';
        }

        // Fallback to supported algorithm
        if (!in_array($algorithm, $acceptedEncodings)) {
            $algorithm = $acceptedEncodings[0] ?? 'gzip';
        }

        return $algorithm;
    }

    /**
     * Get compression options for algorithm and client
     */
    private function getCompressionOptions(array $eventData, string $clientId, string $algorithm): array
    {
        $capabilities = $this->clientCapabilities[$clientId] ?? [];
        $level = $this->getOptimalCompressionLevel($clientId);
        
        $options = [
            'algorithm' => $algorithm,
            'level' => $level,
            'content_type' => 'application/json'
        ];

        // Add algorithm-specific optimizations
        switch ($algorithm) {
            case 'brotli':
                $options['window_size'] = min(22, $capabilities['max_window_size'] ?? 22);
                break;
            case 'lz4':
                $options['level'] = min(16, max(1, $level * 2)); // LZ4 uses different scale
                break;
        }

        return $options;
    }

    /**
     * Compress event data
     */
    private function compressEventData(array $eventData, string $algorithm, array $options): array
    {
        // Separate data that should be compressed
        $compressibleData = [
            'data' => $eventData['data'] ?? null,
            'payload' => $eventData['payload'] ?? null
        ];
        
        // Remove null values
        $compressibleData = array_filter($compressibleData, fn($value) => $value !== null);
        
        if (empty($compressibleData)) {
            return $eventData;
        }

        // Compress the data
        $serializedData = json_encode($compressibleData);
        $compressedData = $this->compression->compress($serializedData, $algorithm, $options);
        
        // Create compressed event structure
        $compressedEvent = $eventData;
        unset($compressedEvent['data'], $compressedEvent['payload']);
        
        $compressedEvent['compressed_data'] = base64_encode($compressedData);
        $compressedEvent['compression'] = [
            'algorithm' => $algorithm,
            'original_size' => strlen($serializedData),
            'compressed_size' => strlen($compressedData)
        ];

        return $compressedEvent;
    }

    /**
     * Check if event data is compressed
     */
    private function isCompressedEvent(array $eventData): bool
    {
        return isset($eventData['compressed_data']) && isset($eventData['compression']);
    }

    /**
     * Decompress event data
     */
    private function decompressEventData(array $compressedEvent): array
    {
        $compressedData = base64_decode($compressedEvent['compressed_data']);
        $decompressedJson = $this->compression->decompress($compressedData);
        $decompressedData = json_decode($decompressedJson, true);
        
        // Reconstruct original event
        $originalEvent = $compressedEvent;
        unset($originalEvent['compressed_data'], $originalEvent['compression']);
        
        return array_merge($originalEvent, $decompressedData);
    }

    /**
     * Get optimal compression level for client
     */
    private function getOptimalCompressionLevel(string $clientId): int
    {
        $capabilities = $this->clientCapabilities[$clientId] ?? [];
        $stats = $this->compressionStats[$clientId] ?? [];
        
        // Start with client's maximum level
        $maxLevel = $capabilities['max_compression_level'] ?? 6;
        
        // Adjust based on client preferences and performance
        if ($capabilities['prefer_speed_over_ratio'] ?? false) {
            return min(3, $maxLevel);
        }
        
        // Adjust based on connection type
        $connectionType = $capabilities['connection_type'] ?? 'unknown';
        switch ($connectionType) {
            case 'mobile':
                return min(4, $maxLevel);
            case 'slow':
                return min(2, $maxLevel);
            case 'fast':
                return $maxLevel;
            default:
                return min(6, $maxLevel);
        }
    }

    /**
     * Initialize adaptive settings
     */
    private function initializeAdaptiveSettings(): void
    {
        $this->adaptiveSettings = [];
    }

    /**
     * Initialize compression statistics
     */
    private function initializeCompressionStats(): void
    {
        $this->compressionStats = [];
    }

    /**
     * Update adaptive settings for client
     */
    private function updateAdaptiveSettings(string $clientId): void
    {
        $capabilities = $this->clientCapabilities[$clientId] ?? [];
        
        $this->adaptiveSettings[$clientId] = [
            'algorithm_preference' => $this->determineAlgorithmPreference($capabilities),
            'compression_threshold' => $this->calculateCompressionThreshold($capabilities),
            'performance_target' => $this->determinePerformanceTarget($capabilities)
        ];
    }

    /**
     * Update compression statistics
     */
    private function updateCompressionStats(string $clientId, array $stats): void
    {
        if (!isset($this->compressionStats[$clientId])) {
            $this->compressionStats[$clientId] = [
                'events_compressed' => 0,
                'total_original_size' => 0,
                'total_compressed_size' => 0,
                'total_compression_time' => 0,
                'algorithms_used' => [],
                'last_updated' => time()
            ];
        }

        $clientStats = &$this->compressionStats[$clientId];
        $clientStats['events_compressed']++;
        $clientStats['total_original_size'] += $stats['original_size'];
        $clientStats['total_compressed_size'] += $stats['compressed_size'];
        $clientStats['total_compression_time'] += $stats['compression_time'];
        $clientStats['last_updated'] = time();
        
        // Track algorithm usage
        $algorithm = $stats['algorithm'];
        if (!isset($clientStats['algorithms_used'][$algorithm])) {
            $clientStats['algorithms_used'][$algorithm] = 0;
        }
        $clientStats['algorithms_used'][$algorithm]++;
    }

    /**
     * Check adaptive compression rules
     */
    private function shouldCompressBasedOnAdaptiveRules(array $eventData, string $clientId): bool
    {
        $adaptiveSettings = $this->adaptiveSettings[$clientId] ?? [];
        
        // Apply adaptive rules here
        return true; // Simplified for now
    }

    /**
     * Helper methods for statistics calculation
     */
    private function calculateAverageCompressionRatio(): float
    {
        $totalOriginal = 0;
        $totalCompressed = 0;
        
        foreach ($this->compressionStats as $stats) {
            $totalOriginal += $stats['total_original_size'];
            $totalCompressed += $stats['total_compressed_size'];
        }
        
        return $totalOriginal > 0 ? $totalCompressed / $totalOriginal : 0;
    }

    private function calculateAverageCompressionTime(): float
    {
        $totalTime = 0;
        $totalEvents = 0;
        
        foreach ($this->compressionStats as $stats) {
            $totalTime += $stats['total_compression_time'];
            $totalEvents += $stats['events_compressed'];
        }
        
        return $totalEvents > 0 ? $totalTime / $totalEvents : 0;
    }

    private function getAlgorithmUsageStats(): array
    {
        $algorithmStats = [];
        
        foreach ($this->compressionStats as $clientStats) {
            foreach ($clientStats['algorithms_used'] as $algorithm => $count) {
                if (!isset($algorithmStats[$algorithm])) {
                    $algorithmStats[$algorithm] = 0;
                }
                $algorithmStats[$algorithm] += $count;
            }
        }
        
        return $algorithmStats;
    }

    private function shouldCompressForClient(string $clientId): bool
    {
        return true; // Simplified
    }

    private function estimateCompressionSavings(string $clientId): float
    {
        $stats = $this->compressionStats[$clientId] ?? [];
        return $this->calculateAverageCompressionRatio();
    }

    private function determineAlgorithmPreference(array $capabilities): string
    {
        return 'auto';
    }

    private function calculateCompressionThreshold(array $capabilities): int
    {
        return $this->config['min_compression_size'];
    }

    private function determinePerformanceTarget(array $capabilities): string
    {
        return $capabilities['latency_tolerance'] ?? 'medium';
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'min_compression_size' => 256,
            'max_compression_level' => 9,
            'default_algorithm' => 'gzip',
            'excluded_event_types' => ['ping', 'pong', 'heartbeat'],
            'adaptive_compression' => true,
            'statistics_enabled' => true,
            'cleanup_interval' => 3600 // 1 hour
        ];
    }
}