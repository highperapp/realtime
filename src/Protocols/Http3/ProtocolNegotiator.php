<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\Http3;

use Amp\Http\Server\Request;
use Psr\Log\LoggerInterface;

/**
 * Protocol Negotiator for HTTP/3, HTTP/2, and HTTP/1.1
 * 
 * Implements intelligent protocol selection based on client capabilities,
 * network conditions, and server configuration
 */
class ProtocolNegotiator
{
    private LoggerInterface $logger;
    private array $config;
    private NetworkConditionDetector $networkDetector;
    private ClientCapabilityDetector $capabilityDetector;
    
    // Protocol priorities (higher = preferred)
    private const PROTOCOL_PRIORITIES = [
        'h3' => 100,
        'h2' => 80,
        'http/1.1' => 60
    ];

    public function __construct(LoggerInterface $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->networkDetector = new NetworkConditionDetector($logger);
        $this->capabilityDetector = new ClientCapabilityDetector($logger);
    }

    /**
     * Negotiate best protocol for client
     */
    public function negotiate(Request $request): \Generator
    {
        $startTime = microtime(true);
        
        try {
            // Detect client capabilities
            $clientCapabilities = yield $this->capabilityDetector->detect($request);
            
            // Detect network conditions
            $networkConditions = yield $this->networkDetector->analyze($request);
            
            // Calculate protocol scores
            $protocolScores = yield $this->calculateProtocolScores(
                $clientCapabilities,
                $networkConditions,
                $request
            );
            
            // Select best protocol
            $selectedProtocol = $this->selectBestProtocol($protocolScores);
            
            $negotiationTime = microtime(true) - $startTime;
            
            $this->logger->debug('Protocol negotiation completed', [
                'selected_protocol' => $selectedProtocol,
                'negotiation_time_ms' => round($negotiationTime * 1000, 2),
                'client_capabilities' => $clientCapabilities,
                'network_conditions' => $networkConditions,
                'protocol_scores' => $protocolScores
            ]);

            return $selectedProtocol;
            
        } catch (\Throwable $e) {
            $this->logger->error('Protocol negotiation failed', [
                'error' => $e->getMessage(),
                'user_agent' => $request->getHeader('user-agent')
            ]);
            
            // Fallback to safest protocol
            return $this->config['fallback_protocol'] ?? 'http/1.1';
        }
    }

    /**
     * Calculate protocol scores based on various factors
     */
    private function calculateProtocolScores(
        array $clientCapabilities,
        array $networkConditions,
        Request $request
    ): \Generator {
        $scores = [];
        
        foreach (self::PROTOCOL_PRIORITIES as $protocol => $baseScore) {
            $score = $baseScore;
            
            // Client capability factors
            $score += $this->calculateClientCapabilityScore($protocol, $clientCapabilities);
            
            // Network condition factors
            $score += $this->calculateNetworkScore($protocol, $networkConditions);
            
            // Request-specific factors
            $score += $this->calculateRequestScore($protocol, $request);
            
            // Server configuration factors
            $score += $this->calculateServerScore($protocol);
            
            $scores[$protocol] = max(0, $score); // Ensure non-negative scores
        }
        
        return $scores;
    }

    /**
     * Calculate client capability score for protocol
     */
    private function calculateClientCapabilityScore(string $protocol, array $capabilities): int
    {
        $score = 0;
        
        switch ($protocol) {
            case 'h3':
                if ($capabilities['supports_quic']) $score += 50;
                if ($capabilities['supports_http3']) $score += 30;
                if ($capabilities['browser_type'] === 'chrome') $score += 20;
                if ($capabilities['browser_type'] === 'firefox') $score += 15;
                if ($capabilities['browser_version'] >= 90) $score += 10;
                break;
                
            case 'h2':
                if ($capabilities['supports_http2']) $score += 40;
                if ($capabilities['supports_server_push']) $score += 20;
                if ($capabilities['supports_multiplexing']) $score += 15;
                if ($capabilities['browser_version'] >= 70) $score += 10;
                break;
                
            case 'http/1.1':
                // Always supported, baseline score
                $score += 30;
                if (!$capabilities['supports_http2']) $score += 20;
                if (!$capabilities['supports_quic']) $score += 15;
                break;
        }
        
        return $score;
    }

    /**
     * Calculate network condition score for protocol
     */
    private function calculateNetworkScore(string $protocol, array $conditions): int
    {
        $score = 0;
        
        $bandwidth = $conditions['estimated_bandwidth'] ?? 0;
        $latency = $conditions['estimated_latency'] ?? 100;
        $packetLoss = $conditions['packet_loss_rate'] ?? 0;
        $connectionType = $conditions['connection_type'] ?? 'unknown';
        
        switch ($protocol) {
            case 'h3':
                // QUIC performs better on high-latency, lossy networks
                if ($latency > 100) $score += 30;
                if ($packetLoss > 0.01) $score += 25; // >1% packet loss
                if ($connectionType === 'mobile') $score += 20;
                if ($bandwidth > 10) $score += 15; // >10 Mbps
                break;
                
            case 'h2':
                // HTTP/2 good for high bandwidth, low latency
                if ($bandwidth > 5) $score += 25;
                if ($latency < 50) $score += 20;
                if ($packetLoss < 0.005) $score += 15; // <0.5% packet loss
                if ($connectionType === 'wifi') $score += 10;
                break;
                
            case 'http/1.1':
                // HTTP/1.1 baseline, good for simple scenarios
                if ($bandwidth < 2) $score += 20; // Low bandwidth
                if ($connectionType === 'dial-up') $score += 30;
                break;
        }
        
        return $score;
    }

    /**
     * Calculate request-specific score for protocol
     */
    private function calculateRequestScore(string $protocol, Request $request): int
    {
        $score = 0;
        
        $method = $request->getMethod();
        $uri = $request->getUri();
        $headers = $request->getHeaders();
        
        switch ($protocol) {
            case 'h3':
                // HTTP/3 good for real-time applications
                if (strpos($uri->getPath(), '/ws') !== false) $score += 40;
                if (strpos($uri->getPath(), '/sse') !== false) $score += 35;
                if (strpos($uri->getPath(), '/api/realtime') !== false) $score += 30;
                if ($method === 'POST' && $this->isStreamingRequest($request)) $score += 25;
                break;
                
            case 'h2':
                // HTTP/2 good for multiple resources
                if ($method === 'GET' && $this->hasMultipleResources($request)) $score += 30;
                if (isset($headers['accept'][0]) && strpos($headers['accept'][0], 'text/html') !== false) $score += 20;
                break;
                
            case 'http/1.1':
                // HTTP/1.1 good for simple requests
                if ($method === 'GET' && $this->isSimpleRequest($request)) $score += 25;
                if ($this->isLegacyAPI($request)) $score += 20;
                break;
        }
        
        return $score;
    }

    /**
     * Calculate server configuration score for protocol
     */
    private function calculateServerScore(string $protocol): int
    {
        $score = 0;
        
        switch ($protocol) {
            case 'h3':
                if ($this->config['prefer_http3']) $score += 30;
                if ($this->config['enable_0rtt']) $score += 20;
                if ($this->config['quic_optimizations']) $score += 15;
                break;
                
            case 'h2':
                if ($this->config['prefer_http2']) $score += 25;
                if ($this->config['enable_server_push']) $score += 15;
                break;
                
            case 'http/1.1':
                if ($this->config['maintain_compatibility']) $score += 20;
                break;
        }
        
        return $score;
    }

    /**
     * Select best protocol based on scores
     */
    private function selectBestProtocol(array $scores): string
    {
        if (empty($scores)) {
            return $this->config['fallback_protocol'] ?? 'http/1.1';
        }
        
        // Sort by score (highest first)
        arsort($scores);
        
        // Get highest scoring protocol
        $bestProtocol = array_key_first($scores);
        $bestScore = $scores[$bestProtocol];
        
        // Check minimum thresholds
        $minThresholds = [
            'h3' => $this->config['min_http3_score'] ?? 150,
            'h2' => $this->config['min_http2_score'] ?? 120,
            'http/1.1' => $this->config['min_http1_score'] ?? 90
        ];
        
        foreach ($scores as $protocol => $score) {
            if ($score >= $minThresholds[$protocol]) {
                return $protocol;
            }
        }
        
        // If no protocol meets threshold, use fallback
        return $this->config['fallback_protocol'] ?? 'http/1.1';
    }

    /**
     * Check if client supports HTTP/3
     */
    public function supportsHttp3(Request $request): bool
    {
        $userAgent = strtolower($request->getHeader('user-agent') ?? '');
        $altSvc = $request->getHeader('alt-svc') ?? '';
        
        // Check Alt-Svc header for HTTP/3 support indication
        if (strpos($altSvc, 'h3=') !== false) {
            return true;
        }
        
        // Check User-Agent for known HTTP/3 capable browsers
        $http3Browsers = [
            'chrome' => 88,  // Chrome 88+ supports HTTP/3
            'firefox' => 88, // Firefox 88+ supports HTTP/3
            'safari' => 14,  // Safari 14+ supports HTTP/3
            'edge' => 88     // Edge 88+ supports HTTP/3
        ];
        
        foreach ($http3Browsers as $browser => $minVersion) {
            if (strpos($userAgent, $browser) !== false) {
                $version = $this->extractBrowserVersion($userAgent, $browser);
                if ($version >= $minVersion) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if request is streaming
     */
    private function isStreamingRequest(Request $request): bool
    {
        $contentType = $request->getHeader('content-type') ?? '';
        $transferEncoding = $request->getHeader('transfer-encoding') ?? '';
        
        return strpos($contentType, 'application/octet-stream') !== false ||
               strpos($transferEncoding, 'chunked') !== false ||
               $request->hasHeader('x-streaming-request');
    }

    /**
     * Check if request involves multiple resources
     */
    private function hasMultipleResources(Request $request): bool
    {
        $accept = $request->getHeader('accept') ?? '';
        $userAgent = $request->getHeader('user-agent') ?? '';
        
        // Check if it's likely a browser request that will fetch multiple resources
        return strpos($accept, 'text/html') !== false &&
               strpos($userAgent, 'Mozilla') !== false;
    }

    /**
     * Check if request is simple
     */
    private function isSimpleRequest(Request $request): bool
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        
        // Simple requests: single files, basic API calls
        return preg_match('/\.(txt|json|xml|css|js|png|jpg|gif)$/', $path) ||
               strpos($path, '/api/simple') !== false;
    }

    /**
     * Check if request is for legacy API
     */
    private function isLegacyAPI(Request $request): bool
    {
        $path = $request->getUri()->getPath();
        $userAgent = $request->getHeader('user-agent') ?? '';
        
        return strpos($path, '/legacy') !== false ||
               strpos($path, '/v1/') !== false ||
               strpos($userAgent, 'curl') !== false ||
               strpos($userAgent, 'wget') !== false;
    }

    /**
     * Extract browser version from User-Agent
     */
    private function extractBrowserVersion(string $userAgent, string $browser): int
    {
        $pattern = '/' . $browser . '\/(\d+)/i';
        
        if (preg_match($pattern, $userAgent, $matches)) {
            return (int)$matches[1];
        }
        
        return 0;
    }

    /**
     * Get adaptive protocol selection based on time and load
     */
    public function getAdaptiveProtocol(Request $request): \Generator
    {
        // Consider server load
        $serverLoad = yield $this->getServerLoad();
        
        // Consider time of day (peak hours might prefer different protocols)
        $hour = (int)date('H');
        $isPeakHour = $hour >= 9 && $hour <= 17; // Business hours
        
        // Base negotiation
        $protocol = yield $this->negotiate($request);
        
        // Adaptive adjustments
        if ($serverLoad > 0.8) {
            // High load: prefer HTTP/1.1 for simplicity
            if ($protocol === 'h3' && $serverLoad > 0.9) {
                $protocol = 'h2';
                $this->logger->debug('Downgraded to HTTP/2 due to high server load', [
                    'load' => $serverLoad
                ]);
            }
        }
        
        if ($isPeakHour && $protocol === 'h3') {
            // Peak hours: be more conservative with HTTP/3
            $clientCapabilities = yield $this->capabilityDetector->detect($request);
            if (!$clientCapabilities['supports_http3_stable']) {
                $protocol = 'h2';
                $this->logger->debug('Downgraded to HTTP/2 during peak hours for stability');
            }
        }
        
        return $protocol;
    }

    /**
     * Get current server load
     */
    private function getServerLoad(): \Generator
    {
        // This would typically integrate with system monitoring
        // For now, return a simulated load based on connection count
        $connectionCount = $this->getActiveConnectionCount();
        $maxConnections = $this->config['max_connections'] ?? 10000;
        
        return min(1.0, $connectionCount / $maxConnections);
    }

    /**
     * Get active connection count (placeholder)
     */
    private function getActiveConnectionCount(): int
    {
        // This would integrate with the actual connection manager
        return 0;
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'prefer_http3' => true,
            'prefer_http2' => true,
            'maintain_compatibility' => true,
            'enable_0rtt' => true,
            'enable_server_push' => true,
            'quic_optimizations' => true,
            'fallback_protocol' => 'http/1.1',
            'min_http3_score' => 150,
            'min_http2_score' => 120,
            'min_http1_score' => 90,
            'adaptive_selection' => true,
            'consider_server_load' => true,
            'peak_hour_conservative' => true,
            'max_connections' => 10000
        ];
    }
}