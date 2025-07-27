<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\WebTransport;

use HighPerApp\HighPer\Compression\CompressionManager;
use Psr\Log\LoggerInterface;

/**
 * WebTransport Compression Manager
 *
 * Handles streaming compression for WebTransport protocol
 * with support for both stream and datagram compression
 */
class WebTransportCompressionManager
{
    private CompressionManager $compressionEngine;
    private LoggerInterface $logger;
    private array $config;
    private array $streamContexts = [];
    private array $stats = [];

    public function __construct(CompressionManager $compressionEngine, LoggerInterface $logger, array $config = [])
    {
        $this->compressionEngine = $compressionEngine;
        $this->logger = $logger;
        $this->config = array_merge([
            'enable_stream_compression' => true,
            'enable_datagram_compression' => false, // Datagrams are typically small and latency-sensitive
            'compression_algorithm' => 'lz4', // Fast compression for real-time
            'compression_level' => 1, // Low level for speed
            'min_compression_size' => 32,
            'stream_compression_threshold' => 0.1, // 10% savings minimum
            'datagram_compression_threshold' => 0.2, // 20% savings minimum for datagrams
            'enable_adaptive_compression' => true,
            'per_stream_context' => true,
            'compression_buffer_size' => 8192,
            'max_compression_contexts' => 1000
        ], $config);

        $this->initializeStats();
    }

    /**
     * Compress data for a WebTransport stream
     */
    public function compressStream(string $data, string $streamId, array $options = []): string
    {
        if (!$this->config['enable_stream_compression']) {
            return $data;
        }

        $startTime = microtime(true);

        try {
            // Check minimum size threshold
            if (strlen($data) < $this->config['min_compression_size']) {
                $this->updateStats('stream_skipped', strlen($data), strlen($data), microtime(true) - $startTime);
                return $data;
            }

            // Get or create compression context for this stream
            $context = $this->getStreamCompressionContext($streamId);

            // Prepare compression options
            $compressionOptions = array_merge([
                'algorithm' => $this->config['compression_algorithm'],
                'level' => $this->config['compression_level'],
                'streaming' => true,
                'context' => $context
            ], $options);

            // Apply adaptive compression if enabled
            if ($this->config['enable_adaptive_compression']) {
                $analysis = $this->compressionEngine->analyzeContent($data);
                if ($analysis['compression_benefit'] < $this->config['stream_compression_threshold']) {
                    $this->updateStats('stream_skipped', strlen($data), strlen($data), microtime(true) - $startTime);
                    return $data;
                }
                
                // Adjust algorithm based on content analysis
                $compressionOptions['algorithm'] = $this->selectOptimalAlgorithm($analysis, 'stream');
            }

            $compressed = $this->compressionEngine->compress($data, $compressionOptions);

            // Update context
            if (isset($compressionOptions['context'])) {
                $this->streamContexts[$streamId] = $compressionOptions['context'];
            }

            $compressionTime = microtime(true) - $startTime;
            $this->updateStats('stream_compressed', strlen($data), strlen($compressed), $compressionTime);

            $this->logger->debug('WebTransport stream data compressed', [
                'stream_id' => $streamId,
                'original_size' => strlen($data),
                'compressed_size' => strlen($compressed),
                'compression_ratio' => strlen($compressed) / strlen($data),
                'algorithm' => $compressionOptions['algorithm'],
                'compression_time_ms' => round($compressionTime * 1000, 2)
            ]);

            return $compressed;

        } catch (\Throwable $e) {
            $this->logger->error('WebTransport stream compression failed', [
                'stream_id' => $streamId,
                'error' => $e->getMessage(),
                'data_size' => strlen($data)
            ]);

            $this->updateStats('stream_failed', strlen($data), strlen($data), microtime(true) - $startTime);
            return $data; // Return original data on compression failure
        }
    }

    /**
     * Decompress data from a WebTransport stream
     */
    public function decompressStream(string $data, string $streamId, array $options = []): string
    {
        if (!$this->config['enable_stream_compression']) {
            return $data;
        }

        $startTime = microtime(true);

        try {
            // Get compression context for this stream
            $context = $this->getStreamCompressionContext($streamId);

            // Prepare decompression options
            $decompressionOptions = array_merge([
                'streaming' => true,
                'context' => $context
            ], $options);

            $decompressed = $this->compressionEngine->decompress($data, $decompressionOptions);

            // Update context
            if (isset($decompressionOptions['context'])) {
                $this->streamContexts[$streamId] = $decompressionOptions['context'];
            }

            $decompressionTime = microtime(true) - $startTime;
            $this->updateStats('stream_decompressed', strlen($data), strlen($decompressed), $decompressionTime);

            $this->logger->debug('WebTransport stream data decompressed', [
                'stream_id' => $streamId,
                'compressed_size' => strlen($data),
                'decompressed_size' => strlen($decompressed),
                'decompression_time_ms' => round($decompressionTime * 1000, 2)
            ]);

            return $decompressed;

        } catch (\Throwable $e) {
            $this->logger->error('WebTransport stream decompression failed', [
                'stream_id' => $streamId,
                'error' => $e->getMessage(),
                'data_size' => strlen($data)
            ]);

            $this->updateStats('stream_decompress_failed', strlen($data), strlen($data), microtime(true) - $startTime);
            throw $e; // Decompression failure is critical
        }
    }

    /**
     * Compress WebTransport datagram (if enabled and beneficial)
     */
    public function compressDatagram(string $data, array $options = []): string
    {
        if (!$this->config['enable_datagram_compression']) {
            return $data;
        }

        $startTime = microtime(true);

        try {
            // Check minimum size threshold
            if (strlen($data) < $this->config['min_compression_size']) {
                $this->updateStats('datagram_skipped', strlen($data), strlen($data), microtime(true) - $startTime);
                return $data;
            }

            // Apply adaptive compression with higher threshold for datagrams
            if ($this->config['enable_adaptive_compression']) {
                $analysis = $this->compressionEngine->analyzeContent($data);
                if ($analysis['compression_benefit'] < $this->config['datagram_compression_threshold']) {
                    $this->updateStats('datagram_skipped', strlen($data), strlen($data), microtime(true) - $startTime);
                    return $data;
                }
            }

            // Use fast compression for datagrams (no streaming context)
            $compressionOptions = array_merge([
                'algorithm' => 'lz4', // Always use fastest algorithm for datagrams
                'level' => 1,
                'streaming' => false
            ], $options);

            $compressed = $this->compressionEngine->compress($data, $compressionOptions);

            $compressionTime = microtime(true) - $startTime;
            $this->updateStats('datagram_compressed', strlen($data), strlen($compressed), $compressionTime);

            $this->logger->debug('WebTransport datagram compressed', [
                'original_size' => strlen($data),
                'compressed_size' => strlen($compressed),
                'compression_ratio' => strlen($compressed) / strlen($data),
                'compression_time_ms' => round($compressionTime * 1000, 2)
            ]);

            return $compressed;

        } catch (\Throwable $e) {
            $this->logger->error('WebTransport datagram compression failed', [
                'error' => $e->getMessage(),
                'data_size' => strlen($data)
            ]);

            $this->updateStats('datagram_failed', strlen($data), strlen($data), microtime(true) - $startTime);
            return $data; // Return original data on compression failure
        }
    }

    /**
     * Decompress WebTransport datagram
     */
    public function decompressDatagram(string $data, array $options = []): string
    {
        if (!$this->config['enable_datagram_compression']) {
            return $data;
        }

        $startTime = microtime(true);

        try {
            $decompressionOptions = array_merge([
                'streaming' => false
            ], $options);

            $decompressed = $this->compressionEngine->decompress($data, $decompressionOptions);

            $decompressionTime = microtime(true) - $startTime;
            $this->updateStats('datagram_decompressed', strlen($data), strlen($decompressed), $decompressionTime);

            $this->logger->debug('WebTransport datagram decompressed', [
                'compressed_size' => strlen($data),
                'decompressed_size' => strlen($decompressed),
                'decompression_time_ms' => round($decompressionTime * 1000, 2)
            ]);

            return $decompressed;

        } catch (\Throwable $e) {
            $this->logger->error('WebTransport datagram decompression failed', [
                'error' => $e->getMessage(),
                'data_size' => strlen($data)
            ]);

            $this->updateStats('datagram_decompress_failed', strlen($data), strlen($data), microtime(true) - $startTime);
            throw $e; // Decompression failure is critical
        }
    }

    /**
     * Get or create compression context for a stream
     */
    private function getStreamCompressionContext(string $streamId): ?array
    {
        if (!$this->config['per_stream_context']) {
            return null;
        }

        if (!isset($this->streamContexts[$streamId])) {
            // Limit number of contexts to prevent memory bloat
            if (count($this->streamContexts) >= $this->config['max_compression_contexts']) {
                // Remove oldest context (simple LRU)
                $oldestStreamId = array_key_first($this->streamContexts);
                unset($this->streamContexts[$oldestStreamId]);
            }

            $this->streamContexts[$streamId] = $this->compressionEngine->createCompressionContext([
                'algorithm' => $this->config['compression_algorithm'],
                'level' => $this->config['compression_level']
            ]);
        }

        return $this->streamContexts[$streamId];
    }

    /**
     * Select optimal compression algorithm based on content analysis
     */
    private function selectOptimalAlgorithm(array $analysis, string $type): string
    {
        // For WebTransport, prioritize speed over compression ratio
        $algorithms = [
            'lz4' => ['speed' => 10, 'ratio' => 5],
            'zstd' => ['speed' => 8, 'ratio' => 8],
            'gzip' => ['speed' => 6, 'ratio' => 7],
            'deflate' => ['speed' => 6, 'ratio' => 7]
        ];

        if ($type === 'stream') {
            // For streams, balance speed and compression
            $weightSpeed = 0.6;
            $weightRatio = 0.4;
        } else {
            // For datagrams, prioritize speed heavily
            $weightSpeed = 0.9;
            $weightRatio = 0.1;
        }

        $bestAlgorithm = 'lz4';
        $bestScore = 0;

        foreach ($algorithms as $algorithm => $metrics) {
            $score = ($metrics['speed'] * $weightSpeed) + ($metrics['ratio'] * $weightRatio);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAlgorithm = $algorithm;
            }
        }

        return $bestAlgorithm;
    }

    /**
     * Remove compression context for a closed stream
     */
    public function removeStreamContext(string $streamId): void
    {
        unset($this->streamContexts[$streamId]);
        
        $this->logger->debug('WebTransport stream compression context removed', [
            'stream_id' => $streamId,
            'remaining_contexts' => count($this->streamContexts)
        ]);
    }

    /**
     * Initialize statistics tracking
     */
    private function initializeStats(): void
    {
        $this->stats = [
            'stream_compressed' => 0,
            'stream_decompressed' => 0,
            'stream_skipped' => 0,
            'stream_failed' => 0,
            'stream_decompress_failed' => 0,
            'datagram_compressed' => 0,
            'datagram_decompressed' => 0,
            'datagram_skipped' => 0,
            'datagram_failed' => 0,
            'datagram_decompress_failed' => 0,
            'total_bytes_in' => 0,
            'total_bytes_out' => 0,
            'total_compression_time' => 0.0,
            'total_decompression_time' => 0.0,
            'bytes_saved' => 0,
            'active_stream_contexts' => 0
        ];
    }

    /**
     * Update compression statistics
     */
    private function updateStats(string $operation, int $inputSize, int $outputSize, float $time): void
    {
        $this->stats[$operation]++;
        $this->stats['total_bytes_in'] += $inputSize;
        $this->stats['total_bytes_out'] += $outputSize;

        if (str_contains($operation, 'compress') && !str_contains($operation, 'decompress')) {
            $this->stats['total_compression_time'] += $time;
            $this->stats['bytes_saved'] += max(0, $inputSize - $outputSize);
        } elseif (str_contains($operation, 'decompress')) {
            $this->stats['total_decompression_time'] += $time;
        }

        $this->stats['active_stream_contexts'] = count($this->streamContexts);
    }

    /**
     * Get compression statistics
     */
    public function getStats(): array
    {
        $stats = $this->stats;
        
        // Calculate derived metrics
        if ($stats['total_bytes_in'] > 0) {
            $stats['overall_compression_ratio'] = $stats['total_bytes_out'] / $stats['total_bytes_in'];
            $stats['compression_efficiency'] = ($stats['bytes_saved'] / $stats['total_bytes_in']) * 100;
        } else {
            $stats['overall_compression_ratio'] = 1.0;
            $stats['compression_efficiency'] = 0.0;
        }

        $totalOperations = $stats['stream_compressed'] + $stats['datagram_compressed'];
        if ($totalOperations > 0) {
            $stats['average_compression_time_ms'] = 
                ($stats['total_compression_time'] / $totalOperations) * 1000;
        } else {
            $stats['average_compression_time_ms'] = 0.0;
        }

        return $stats;
    }

    /**
     * Reset compression contexts (for cleanup or testing)
     */
    public function resetContexts(): void
    {
        $this->streamContexts = [];
        $this->logger->info('WebTransport compression contexts reset');
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);
        
        $this->logger->info('WebTransport compression configuration updated', [
            'updated_keys' => array_keys($newConfig)
        ]);
    }
}