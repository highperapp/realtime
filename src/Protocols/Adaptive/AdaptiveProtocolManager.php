<?php

declare(strict_types=1);

namespace EaseAppPHP\HighPer\Realtime\Protocols\Adaptive;

use Amp\Http\Server\Request;
use EaseAppPHP\HighPer\Realtime\Protocols\ProtocolInterface;
use EaseAppPHP\HighPer\Realtime\Protocols\Http3\Http3Server;
use EaseAppPHP\HighPer\Realtime\Protocols\WebSocket\WebSocketHttp3Protocol;
use EaseAppPHP\HighPer\Realtime\Protocols\WebTransport\WebTransportProtocol;
use EaseAppPHP\HighPer\Realtime\Protocols\WebRTC\WebRTCDataChannelProtocol;
use Psr\Log\LoggerInterface;

/**
 * Adaptive Protocol Manager
 * 
 * Intelligently selects optimal protocol based on network conditions,
 * client capabilities, and application requirements with automatic
 * quality adaptation and graceful degradation
 */
class AdaptiveProtocolManager
{
    private LoggerInterface $logger;
    private array $config;
    private NetworkMonitor $networkMonitor;
    private QualityManager $qualityManager;
    private ProtocolAnalyzer $protocolAnalyzer;
    private LoadBalancer $loadBalancer;
    
    private array $availableProtocols = [];
    private array $activeConnections = [];
    private array $protocolMetrics = [];
    private array $adaptationRules = [];
    private bool $isRunning = false;

    // Protocol priority matrix based on use cases
    private const PROTOCOL_MATRIX = [
        'gaming' => [
            'primary' => ['webrtc', 'webtransport', 'websocket-http3'],
            'fallback' => ['websocket-http2', 'sse'],
            'requirements' => ['low_latency', 'unreliable_ok', 'p2p_preferred']
        ],
        'financial' => [
            'primary' => ['websocket-http3', 'webtransport', 'http3'],
            'fallback' => ['websocket-http2', 'http2'],
            'requirements' => ['reliable', 'encrypted', 'ordered']
        ],
        'collaboration' => [
            'primary' => ['webrtc', 'websocket-http3', 'webtransport'],
            'fallback' => ['websocket-http2', 'sse'],
            'requirements' => ['real_time', 'multiplexing', 'bidirectional']
        ],
        'streaming' => [
            'primary' => ['webtransport', 'websocket-http3', 'http3'],
            'fallback' => ['websocket-http2', 'sse'],
            'requirements' => ['high_throughput', 'flow_control', 'adaptive_quality']
        ],
        'iot' => [
            'primary' => ['webtransport', 'websocket-http3'],
            'fallback' => ['websocket-http2', 'http2'],
            'requirements' => ['low_power', 'connection_migration', 'compression']
        ]
    ];

    public function __construct(LoggerInterface $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->initializeComponents();
        $this->initializeProtocols();
        $this->initializeAdaptationRules();
    }

    /**
     * Initialize adaptive components
     */
    private function initializeComponents(): void
    {
        $this->networkMonitor = new NetworkMonitor($this->logger, $this->config);
        $this->qualityManager = new QualityManager($this->logger, $this->config);
        $this->protocolAnalyzer = new ProtocolAnalyzer($this->logger, $this->config);
        $this->loadBalancer = new LoadBalancer($this->logger, $this->config);
    }

    /**
     * Initialize available protocols
     */
    private function initializeProtocols(): void
    {
        $this->availableProtocols = [
            'http3' => null, // Will be injected
            'websocket-http3' => null,
            'webtransport' => null,
            'webrtc' => null,
            'websocket-http2' => null,
            'sse' => null,
            'http2' => null,
            'http1.1' => null
        ];
        
        $this->protocolMetrics = array_fill_keys(array_keys($this->availableProtocols), [
            'connections' => 0,
            'success_rate' => 1.0,
            'average_latency' => 0,
            'throughput' => 0,
            'error_rate' => 0,
            'last_used' => 0
        ]);
    }

    /**
     * Initialize adaptation rules
     */
    private function initializeAdaptationRules(): void
    {
        $this->adaptationRules = [
            'network_conditions' => [
                'high_latency' => ['threshold' => 200, 'action' => 'prefer_quic'],
                'packet_loss' => ['threshold' => 0.01, 'action' => 'prefer_reliable'],
                'low_bandwidth' => ['threshold' => 1, 'action' => 'enable_compression'],
                'mobile_network' => ['threshold' => 0.5, 'action' => 'enable_migration']
            ],
            'server_load' => [
                'high_cpu' => ['threshold' => 0.8, 'action' => 'prefer_p2p'],
                'high_memory' => ['threshold' => 0.85, 'action' => 'limit_connections'],
                'high_connections' => ['threshold' => 0.9, 'action' => 'fallback_protocols']
            ],
            'quality_metrics' => [
                'low_fps' => ['threshold' => 24, 'action' => 'reduce_quality'],
                'high_jitter' => ['threshold' => 50, 'action' => 'increase_buffer'],
                'connection_drops' => ['threshold' => 0.05, 'action' => 'switch_protocol']
            ]
        ];
    }

    /**
     * Register protocol implementation
     */
    public function registerProtocol(string $name, ProtocolInterface $protocol): void
    {
        $this->availableProtocols[$name] = $protocol;
        
        $this->logger->debug('Protocol registered', [
            'protocol' => $name,
            'version' => $protocol->getVersion()
        ]);
    }

    /**
     * Select optimal protocol for request
     */
    public function selectProtocol(Request $request, array $requirements = []): \Generator
    {
        $startTime = microtime(true);
        
        try {
            // Analyze request context
            $context = yield $this->analyzeRequestContext($request, $requirements);
            
            // Get network conditions
            $networkConditions = yield $this->networkMonitor->analyze($request);
            
            // Get server load
            $serverLoad = yield $this->getServerLoad();
            
            // Calculate protocol scores
            $protocolScores = yield $this->calculateProtocolScores(
                $context,
                $networkConditions,
                $serverLoad,
                $requirements
            );
            
            // Apply adaptation rules
            $adaptedScores = yield $this->applyAdaptationRules(
                $protocolScores,
                $networkConditions,
                $serverLoad
            );
            
            // Select best protocol
            $selectedProtocol = $this->selectBestProtocol($adaptedScores);
            
            // Validate protocol availability
            if (!isset($this->availableProtocols[$selectedProtocol]) || 
                !$this->availableProtocols[$selectedProtocol]) {
                $selectedProtocol = yield $this->getFallbackProtocol($selectedProtocol, $context);
            }
            
            $selectionTime = microtime(true) - $startTime;
            
            $this->logger->info('Protocol selected', [
                'protocol' => $selectedProtocol,
                'selection_time_ms' => round($selectionTime * 1000, 2),
                'context' => $context['application_type'],
                'network_score' => $networkConditions['score'] ?? 0,
                'server_load' => $serverLoad,
                'scores' => $adaptedScores
            ]);

            // Update protocol metrics
            $this->updateProtocolMetrics($selectedProtocol, true);

            return [
                'protocol' => $selectedProtocol,
                'implementation' => $this->availableProtocols[$selectedProtocol],
                'quality_config' => yield $this->getQualityConfig($selectedProtocol, $networkConditions),
                'fallback_chain' => $this->getFallbackChain($selectedProtocol, $context),
                'adaptation_config' => $this->getAdaptationConfig($selectedProtocol)
            ];
            
        } catch (\Throwable $e) {
            $this->logger->error('Protocol selection failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            // Return safest fallback
            return [
                'protocol' => 'http1.1',
                'implementation' => $this->availableProtocols['http1.1'],
                'quality_config' => $this->getDefaultQualityConfig(),
                'fallback_chain' => ['http1.1'],
                'adaptation_config' => []
            ];
        }
    }

    /**
     * Analyze request context
     */
    private function analyzeRequestContext(Request $request, array $requirements): \Generator
    {
        $uri = $request->getUri();
        $userAgent = $request->getHeader('user-agent') ?? '';
        $path = $uri->getPath();
        
        // Determine application type
        $applicationType = $this->detectApplicationType($path, $userAgent, $requirements);
        
        // Analyze client capabilities
        $clientCapabilities = yield $this->protocolAnalyzer->analyzeClient($request);
        
        // Detect device type
        $deviceType = $this->detectDeviceType($userAgent);
        
        // Get connection history
        $connectionHistory = $this->getConnectionHistory($request);
        
        return [
            'application_type' => $applicationType,
            'client_capabilities' => $clientCapabilities,
            'device_type' => $deviceType,
            'connection_history' => $connectionHistory,
            'requirements' => $requirements,
            'path' => $path,
            'user_agent' => $userAgent
        ];
    }

    /**
     * Calculate protocol scores
     */
    private function calculateProtocolScores(
        array $context,
        array $networkConditions,
        array $serverLoad,
        array $requirements
    ): \Generator {
        $scores = [];
        $applicationType = $context['application_type'];
        
        // Get protocol preferences for application type
        $preferences = self::PROTOCOL_MATRIX[$applicationType] ?? 
                      self::PROTOCOL_MATRIX['collaboration'];
        
        foreach ($this->availableProtocols as $protocolName => $protocol) {
            if (!$protocol) continue;
            
            $score = 0;
            
            // Base score from application preferences
            if (in_array($protocolName, $preferences['primary'])) {
                $score += 100;
            } elseif (in_array($protocolName, $preferences['fallback'])) {
                $score += 60;
            } else {
                $score += 30;
            }
            
            // Client capability score
            $score += $this->calculateClientCapabilityScore(
                $protocolName, 
                $context['client_capabilities']
            );
            
            // Network condition score
            $score += $this->calculateNetworkScore(
                $protocolName, 
                $networkConditions
            );
            
            // Server load score
            $score += $this->calculateServerLoadScore(
                $protocolName, 
                $serverLoad
            );
            
            // Historical performance score
            $score += $this->calculateHistoricalScore($protocolName);
            
            // Requirements match score
            $score += $this->calculateRequirementsScore(
                $protocolName, 
                $requirements, 
                $preferences['requirements']
            );
            
            // Device type optimization
            $score += $this->calculateDeviceScore(
                $protocolName, 
                $context['device_type']
            );
            
            $scores[$protocolName] = max(0, $score);
        }
        
        return $scores;
    }

    /**
     * Apply adaptation rules
     */
    private function applyAdaptationRules(
        array $protocolScores,
        array $networkConditions,
        array $serverLoad
    ): \Generator {
        $adaptedScores = $protocolScores;
        
        // Network condition adaptations
        foreach ($this->adaptationRules['network_conditions'] as $condition => $rule) {
            if ($this->conditionMet($condition, $networkConditions, $rule['threshold'])) {
                $adaptedScores = $this->applyNetworkAdaptation(
                    $adaptedScores, 
                    $rule['action']
                );
            }
        }
        
        // Server load adaptations
        foreach ($this->adaptationRules['server_load'] as $condition => $rule) {
            if ($this->conditionMet($condition, $serverLoad, $rule['threshold'])) {
                $adaptedScores = $this->applyServerLoadAdaptation(
                    $adaptedScores, 
                    $rule['action']
                );
            }
        }
        
        return $adaptedScores;
    }

    /**
     * Apply network-based adaptations
     */
    private function applyNetworkAdaptation(array $scores, string $action): array
    {
        switch ($action) {
            case 'prefer_quic':
                $scores['http3'] = ($scores['http3'] ?? 0) * 1.5;
                $scores['websocket-http3'] = ($scores['websocket-http3'] ?? 0) * 1.4;
                $scores['webtransport'] = ($scores['webtransport'] ?? 0) * 1.3;
                break;
                
            case 'prefer_reliable':
                $scores['websocket-http3'] = ($scores['websocket-http3'] ?? 0) * 1.3;
                $scores['websocket-http2'] = ($scores['websocket-http2'] ?? 0) * 1.2;
                $scores['webrtc'] = ($scores['webrtc'] ?? 0) * 0.8; // Reduce P2P preference
                break;
                
            case 'enable_compression':
                // Favor protocols with better compression
                $scores['http3'] = ($scores['http3'] ?? 0) * 1.2;
                $scores['websocket-http3'] = ($scores['websocket-http3'] ?? 0) * 1.2;
                break;
                
            case 'enable_migration':
                $scores['http3'] = ($scores['http3'] ?? 0) * 1.4;
                $scores['webtransport'] = ($scores['webtransport'] ?? 0) * 1.3;
                break;
        }
        
        return $scores;
    }

    /**
     * Apply server load adaptations
     */
    private function applyServerLoadAdaptation(array $scores, string $action): array
    {
        switch ($action) {
            case 'prefer_p2p':
                $scores['webrtc'] = ($scores['webrtc'] ?? 0) * 1.5;
                break;
                
            case 'limit_connections':
                // Reduce scores for connection-heavy protocols
                $scores['websocket-http3'] = ($scores['websocket-http3'] ?? 0) * 0.8;
                $scores['websocket-http2'] = ($scores['websocket-http2'] ?? 0) * 0.7;
                break;
                
            case 'fallback_protocols':
                // Prefer simpler protocols under high load
                $scores['http2'] = ($scores['http2'] ?? 0) * 1.3;
                $scores['http1.1'] = ($scores['http1.1'] ?? 0) * 1.2;
                $scores['http3'] = ($scores['http3'] ?? 0) * 0.8;
                break;
        }
        
        return $scores;
    }

    /**
     * Select best protocol from scores
     */
    private function selectBestProtocol(array $scores): string
    {
        if (empty($scores)) {
            return 'http1.1';
        }
        
        // Sort by score (highest first)
        arsort($scores);
        
        return array_key_first($scores);
    }

    /**
     * Get quality configuration for protocol
     */
    private function getQualityConfig(string $protocol, array $networkConditions): \Generator
    {
        return yield $this->qualityManager->getConfigForProtocol(
            $protocol, 
            $networkConditions
        );
    }

    /**
     * Get fallback chain for protocol
     */
    private function getFallbackChain(string $protocol, array $context): array
    {
        $applicationType = $context['application_type'];
        $preferences = self::PROTOCOL_MATRIX[$applicationType] ?? 
                      self::PROTOCOL_MATRIX['collaboration'];
        
        $chain = [];
        
        // Add primary protocols
        foreach ($preferences['primary'] as $primaryProtocol) {
            if ($primaryProtocol !== $protocol && 
                isset($this->availableProtocols[$primaryProtocol])) {
                $chain[] = $primaryProtocol;
            }
        }
        
        // Add fallback protocols
        foreach ($preferences['fallback'] as $fallbackProtocol) {
            if (!in_array($fallbackProtocol, $chain) && 
                isset($this->availableProtocols[$fallbackProtocol])) {
                $chain[] = $fallbackProtocol;
            }
        }
        
        // Ensure we always have http1.1 as final fallback
        if (!in_array('http1.1', $chain)) {
            $chain[] = 'http1.1';
        }
        
        return $chain;
    }

    /**
     * Handle protocol switch during connection
     */
    public function handleProtocolSwitch(string $connectionId, array $metrics): \Generator
    {
        if (!isset($this->activeConnections[$connectionId])) {
            return null;
        }
        
        $connection = $this->activeConnections[$connectionId];
        $currentProtocol = $connection['protocol'];
        
        // Analyze if switch is needed
        $switchNeeded = $this->analyzeMetricsForSwitch($metrics, $currentProtocol);
        
        if (!$switchNeeded) {
            return null;
        }
        
        // Get fallback chain
        $fallbackChain = $connection['fallback_chain'];
        
        // Find next protocol in chain
        $currentIndex = array_search($currentProtocol, $fallbackChain);
        if ($currentIndex === false || $currentIndex >= count($fallbackChain) - 1) {
            $this->logger->warning('No more fallback protocols available', [
                'connection_id' => $connectionId,
                'current_protocol' => $currentProtocol
            ]);
            return null;
        }
        
        $nextProtocol = $fallbackChain[$currentIndex + 1];
        
        $this->logger->info('Switching protocol', [
            'connection_id' => $connectionId,
            'from' => $currentProtocol,
            'to' => $nextProtocol,
            'reason' => $switchNeeded
        ]);
        
        // Update connection record
        $this->activeConnections[$connectionId]['protocol'] = $nextProtocol;
        
        // Update metrics
        $this->updateProtocolMetrics($currentProtocol, false);
        $this->updateProtocolMetrics($nextProtocol, true);
        
        return [
            'new_protocol' => $nextProtocol,
            'implementation' => $this->availableProtocols[$nextProtocol],
            'quality_config' => yield $this->qualityManager->getConfigForProtocol(
                $nextProtocol, 
                $metrics['network_conditions'] ?? []
            )
        ];
    }

    /**
     * Start adaptive monitoring
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting Adaptive Protocol Manager');

        // Start network monitoring
        yield $this->networkMonitor->start();
        
        // Start quality monitoring
        yield $this->qualityManager->start();
        
        $this->isRunning = true;
        
        // Start adaptation loop
        \Amp\async(function() {
            yield $this->startAdaptationLoop();
        });

        $this->logger->info('Adaptive Protocol Manager started');
    }

    /**
     * Stop adaptive manager
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping Adaptive Protocol Manager');

        yield $this->networkMonitor->stop();
        yield $this->qualityManager->stop();
        
        $this->isRunning = false;
        
        $this->logger->info('Adaptive Protocol Manager stopped');
    }

    /**
     * Get current metrics
     */
    public function getMetrics(): array
    {
        return [
            'protocol_metrics' => $this->protocolMetrics,
            'active_connections' => count($this->activeConnections),
            'adaptation_rules_triggered' => $this->getAdaptationStats(),
            'network_conditions' => $this->networkMonitor->getCurrentConditions(),
            'quality_metrics' => $this->qualityManager->getGlobalMetrics()
        ];
    }

    /**
     * Register active connection
     */
    public function registerConnection(string $connectionId, array $connectionInfo): void
    {
        $this->activeConnections[$connectionId] = $connectionInfo;
        $this->updateProtocolMetrics($connectionInfo['protocol'], true);
    }

    /**
     * Unregister connection
     */
    public function unregisterConnection(string $connectionId): void
    {
        if (isset($this->activeConnections[$connectionId])) {
            $protocol = $this->activeConnections[$connectionId]['protocol'];
            $this->updateProtocolMetrics($protocol, false);
            unset($this->activeConnections[$connectionId]);
        }
    }

    /**
     * Helper methods for scoring
     */
    private function calculateClientCapabilityScore(string $protocol, array $capabilities): int
    {
        $score = 0;
        
        switch ($protocol) {
            case 'http3':
            case 'websocket-http3':
            case 'webtransport':
                if ($capabilities['supports_http3'] ?? false) $score += 40;
                if ($capabilities['supports_quic'] ?? false) $score += 30;
                break;
                
            case 'webrtc':
                if ($capabilities['supports_webrtc'] ?? false) $score += 50;
                if ($capabilities['supports_datachannel'] ?? false) $score += 20;
                break;
                
            case 'websocket-http2':
                if ($capabilities['supports_websocket'] ?? false) $score += 30;
                if ($capabilities['supports_http2'] ?? false) $score += 20;
                break;
        }
        
        return $score;
    }

    private function calculateNetworkScore(string $protocol, array $conditions): int
    {
        $score = 0;
        $latency = $conditions['latency'] ?? 100;
        $bandwidth = $conditions['bandwidth'] ?? 10;
        $packetLoss = $conditions['packet_loss'] ?? 0;
        
        switch ($protocol) {
            case 'http3':
            case 'webtransport':
                // QUIC performs better on lossy networks
                if ($packetLoss > 0.01) $score += 30;
                if ($latency > 100) $score += 25;
                break;
                
            case 'webrtc':
                // P2P reduces server load
                if ($bandwidth > 5) $score += 20;
                break;
        }
        
        return $score;
    }

    private function calculateServerLoadScore(string $protocol, array $load): int
    {
        $score = 0;
        $cpu = $load['cpu'] ?? 0.5;
        $memory = $load['memory'] ?? 0.5;
        
        if ($protocol === 'webrtc' && ($cpu > 0.7 || $memory > 0.7)) {
            $score += 40; // Prefer P2P under high load
        }
        
        return $score;
    }

    private function calculateHistoricalScore(string $protocol): int
    {
        $metrics = $this->protocolMetrics[$protocol] ?? [];
        $successRate = $metrics['success_rate'] ?? 1.0;
        $avgLatency = $metrics['average_latency'] ?? 100;
        
        $score = (int)($successRate * 30);
        
        if ($avgLatency < 50) {
            $score += 20;
        } elseif ($avgLatency > 200) {
            $score -= 10;
        }
        
        return $score;
    }

    private function updateProtocolMetrics(string $protocol, bool $success): void
    {
        if (!isset($this->protocolMetrics[$protocol])) {
            return;
        }
        
        $metrics = &$this->protocolMetrics[$protocol];
        
        if ($success) {
            $metrics['connections']++;
            $metrics['last_used'] = time();
        } else {
            $metrics['error_rate'] = min(1.0, $metrics['error_rate'] + 0.01);
        }
        
        // Update success rate (exponential moving average)
        $alpha = 0.1;
        $metrics['success_rate'] = (1 - $alpha) * $metrics['success_rate'] + 
                                  $alpha * ($success ? 1.0 : 0.0);
    }

    /**
     * Additional helper methods
     */
    private function detectApplicationType(string $path, string $userAgent, array $requirements): string
    {
        // Check explicit requirements
        if (isset($requirements['application_type'])) {
            return $requirements['application_type'];
        }
        
        // Detect from path
        if (strpos($path, '/game') !== false || strpos($path, '/gaming') !== false) {
            return 'gaming';
        }
        if (strpos($path, '/trading') !== false || strpos($path, '/finance') !== false) {
            return 'financial';
        }
        if (strpos($path, '/collaborate') !== false || strpos($path, '/editor') !== false) {
            return 'collaboration';
        }
        if (strpos($path, '/stream') !== false || strpos($path, '/video') !== false) {
            return 'streaming';
        }
        if (strpos($path, '/iot') !== false || strpos($path, '/sensor') !== false) {
            return 'iot';
        }
        
        return 'collaboration'; // Default
    }

    private function detectDeviceType(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);
        
        if (strpos($userAgent, 'mobile') !== false || strpos($userAgent, 'android') !== false) {
            return 'mobile';
        }
        if (strpos($userAgent, 'tablet') !== false || strpos($userAgent, 'ipad') !== false) {
            return 'tablet';
        }
        
        return 'desktop';
    }

    private function getDefaultConfig(): array
    {
        return [
            'adaptation_interval' => 30, // seconds
            'quality_adaptation_enabled' => true,
            'protocol_switching_enabled' => true,
            'network_monitoring_enabled' => true,
            'load_balancing_enabled' => true,
            'fallback_timeout' => 5, // seconds
            'metrics_retention' => 3600 // 1 hour
        ];
    }

    private function startAdaptationLoop(): \Generator
    {
        while ($this->isRunning) {
            yield \Amp\delay($this->config['adaptation_interval'] * 1000);
            
            try {
                // Check all active connections for adaptation needs
                foreach ($this->activeConnections as $connectionId => $connection) {
                    $metrics = yield $this->gatherConnectionMetrics($connectionId);
                    yield $this->handleProtocolSwitch($connectionId, $metrics);
                }
                
                // Update global metrics
                yield $this->updateGlobalMetrics();
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in adaptation loop', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    // Placeholder methods that would be implemented with full monitoring
    private function getServerLoad(): \Generator { return yield ['cpu' => 0.5, 'memory' => 0.5]; }
    private function conditionMet(string $condition, array $data, float $threshold): bool { return false; }
    private function analyzeMetricsForSwitch(array $metrics, string $protocol): ?string { return null; }
    private function getAdaptationStats(): array { return []; }
    private function gatherConnectionMetrics(string $connectionId): \Generator { return yield []; }
    private function updateGlobalMetrics(): \Generator { return yield; }
    private function getConnectionHistory(Request $request): array { return []; }
    private function getFallbackProtocol(string $protocol, array $context): \Generator { return yield 'http1.1'; }
    private function getAdaptationConfig(string $protocol): array { return []; }
    private function getDefaultQualityConfig(): array { return []; }
    private function calculateRequirementsScore(string $protocol, array $requirements, array $preferences): int { return 0; }
    private function calculateDeviceScore(string $protocol, string $deviceType): int { return 0; }
}