<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Instrumentation;

use HighPerApp\HighPer\Monitoring\Facades\Monitor;
use HighPerApp\HighPer\Tracing\Facades\Trace;
use HighPerApp\HighPer\Realtime\Protocols\WebTransportProtocol;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * WebTransport Protocol Auto-Instrumentation
 * 
 * Provides automatic monitoring and tracing for WebTransport sessions,
 * bidirectional streams, datagrams, and QUIC transport with comprehensive metrics.
 */
class WebTransportInstrumentation
{
    private LoggerInterface $logger;
    private array $config;
    private array $activeSessions = [];
    private array $activeStreams = [];
    private array $datagramMetrics = [];
    private array $transportMetrics = [];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'enabled' => true,
            'sample_sessions' => true,
            'sample_streams' => true,
            'sample_datagrams' => true,
            'sample_rate' => 0.1, // 10% sampling for WebTransport
            'track_session_lifecycle' => true,
            'track_stream_management' => true,
            'track_datagram_delivery' => true,
            'track_quic_transport' => true,
            'security_monitoring' => true,
            'performance_monitoring' => true,
            'reliability_monitoring' => true
        ], $config);
    }

    /**
     * Instrument WebTransport protocol
     */
    public function instrument(WebTransportProtocol $protocol): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $this->instrumentSessionLifecycle($protocol);
        $this->instrumentStreamManagement($protocol);
        $this->instrumentDatagramDelivery($protocol);
        $this->instrumentQuicTransport($protocol);
        $this->instrumentPerformanceMetrics($protocol);
        $this->instrumentReliabilityMetrics($protocol);

        $this->logger->info('WebTransport instrumentation enabled', [
            'sample_rate' => $this->config['sample_rate'],
            'features' => array_keys(array_filter($this->config))
        ]);
    }

    /**
     * Instrument WebTransport session lifecycle
     */
    private function instrumentSessionLifecycle(WebTransportProtocol $protocol): void
    {
        if (!$this->config['track_session_lifecycle']) {
            return;
        }

        // Session established
        $protocol->onSessionEstablished(function(string $sessionId, array $sessionInfo) {
            $clientAddress = $sessionInfo['client_address'] ?? 'unknown';
            $protocol_version = $sessionInfo['protocol_version'] ?? 'draft-02';
            $alpn = $sessionInfo['alpn'] ?? 'h3-webtransport';
            
            // Record session metrics
            Monitor::increment('webtransport.sessions.established');
            Monitor::gauge('webtransport.sessions.active', $this->getActiveSessionCount() + 1);
            Monitor::increment("webtransport.sessions.protocol.{$protocol_version}");
            
            // Track session details
            $this->activeSessions[$sessionId] = [
                'id' => $sessionId,
                'client_address' => $clientAddress,
                'protocol_version' => $protocol_version,
                'alpn' => $alpn,
                'established_at' => microtime(true),
                'streams_created' => 0,
                'datagrams_sent' => 0,
                'datagrams_received' => 0,
                'bytes_sent' => 0,
                'bytes_received' => 0,
                'last_activity' => microtime(true)
            ];

            // Create session span if sampling
            if ($this->shouldSample('session')) {
                $span = Trace::startSpan('webtransport.session', [
                    'session.id' => $sessionId,
                    'session.client_address' => $clientAddress,
                    'webtransport.protocol_version' => $protocol_version,
                    'webtransport.alpn' => $alpn,
                    'protocol' => 'webtransport',
                    'span.kind' => 'server'
                ]);
                
                $this->activeSessions[$sessionId]['span_id'] = $span;
            }

            // Security monitoring
            if ($this->config['security_monitoring']) {
                $this->monitorSessionSecurity($sessionInfo);
            }

            $this->logger->debug('WebTransport session established', [
                'session_id' => $sessionId,
                'client_address' => $clientAddress,
                'protocol_version' => $protocol_version
            ]);
        });

        // Session closed
        $protocol->onSessionClosed(function(string $sessionId, ?int $errorCode = null, ?string $reason = null) {
            if (!isset($this->activeSessions[$sessionId])) {
                return;
            }

            $sessionData = $this->activeSessions[$sessionId];
            $duration = microtime(true) - $sessionData['established_at'];
            
            // Record session closure metrics
            Monitor::increment('webtransport.sessions.closed');
            Monitor::gauge('webtransport.sessions.active', $this->getActiveSessionCount() - 1);
            Monitor::timing('webtransport.session.duration', $duration * 1000);
            
            if ($errorCode) {
                Monitor::increment('webtransport.sessions.error');
                Monitor::increment("webtransport.sessions.error.{$errorCode}");
            }

            // Record session summary
            Monitor::gauge('webtransport.session.streams_created', $sessionData['streams_created']);
            Monitor::gauge('webtransport.session.datagrams_total', 
                $sessionData['datagrams_sent'] + $sessionData['datagrams_received']);
            Monitor::gauge('webtransport.session.bytes_total', 
                $sessionData['bytes_sent'] + $sessionData['bytes_received']);

            // Finish session span
            if (isset($sessionData['span_id'])) {
                Trace::finishSpan($sessionData['span_id'], [
                    'session.duration_ms' => round($duration * 1000, 2),
                    'webtransport.streams_created' => $sessionData['streams_created'],
                    'webtransport.datagrams_total' => $sessionData['datagrams_sent'] + $sessionData['datagrams_received'],
                    'webtransport.bytes_total' => $sessionData['bytes_sent'] + $sessionData['bytes_received'],
                    'session.error_code' => $errorCode,
                    'session.close_reason' => $reason
                ]);
            }

            // Clean up
            unset($this->activeSessions[$sessionId]);

            $this->logger->debug('WebTransport session closed', [
                'session_id' => $sessionId,
                'duration_ms' => round($duration * 1000, 2),
                'error_code' => $errorCode,
                'reason' => $reason
            ]);
        });
    }

    /**
     * Instrument bidirectional stream management
     */
    private function instrumentStreamManagement(WebTransportProtocol $protocol): void
    {
        if (!$this->config['track_stream_management']) {
            return;
        }

        // Stream opened
        $protocol->onStreamOpened(function(string $sessionId, string $streamId, string $direction) {
            // Update session metrics
            if (isset($this->activeSessions[$sessionId])) {
                $this->activeSessions[$sessionId]['streams_created']++;
                $this->activeSessions[$sessionId]['last_activity'] = microtime(true);
            }
            
            // Record stream metrics
            Monitor::increment('webtransport.streams.opened');
            Monitor::increment("webtransport.streams.direction.{$direction}");
            Monitor::gauge('webtransport.streams.active', $this->getActiveStreamCount() + 1);
            
            // Track stream details
            $this->activeStreams[$streamId] = [
                'id' => $streamId,
                'session_id' => $sessionId,
                'direction' => $direction,
                'opened_at' => microtime(true),
                'bytes_sent' => 0,
                'bytes_received' => 0,
                'messages_sent' => 0,
                'messages_received' => 0,
                'flow_control_blocked' => 0
            ];

            // Create stream span if sampling
            if ($this->shouldSample('stream')) {
                $span = Trace::startSpan('webtransport.stream', [
                    'stream.id' => $streamId,
                    'session.id' => $sessionId,
                    'webtransport.direction' => $direction,
                    'protocol' => 'webtransport'
                ]);
                
                $this->activeStreams[$streamId]['span_id'] = $span;
            }

            $this->logger->debug('WebTransport stream opened', [
                'session_id' => $sessionId,
                'stream_id' => $streamId,
                'direction' => $direction
            ]);
        });

        // Stream data transfer
        $protocol->onStreamData(function(string $streamId, string $data, string $direction) {
            if (!isset($this->activeStreams[$streamId])) {
                return;
            }

            $dataSize = strlen($data);
            $sessionId = $this->activeStreams[$streamId]['session_id'];
            
            // Update stream metrics
            if ($direction === 'sent') {
                $this->activeStreams[$streamId]['bytes_sent'] += $dataSize;
                $this->activeStreams[$streamId]['messages_sent']++;
                Monitor::gauge('webtransport.stream.bytes_sent', $dataSize);
                
                // Update session metrics
                if (isset($this->activeSessions[$sessionId])) {
                    $this->activeSessions[$sessionId]['bytes_sent'] += $dataSize;
                }
            } else {
                $this->activeStreams[$streamId]['bytes_received'] += $dataSize;
                $this->activeStreams[$streamId]['messages_received']++;
                Monitor::gauge('webtransport.stream.bytes_received', $dataSize);
                
                // Update session metrics
                if (isset($this->activeSessions[$sessionId])) {
                    $this->activeSessions[$sessionId]['bytes_received'] += $dataSize;
                }
            }

            Monitor::increment('webtransport.stream.data_transfers');
            
            // Track large messages
            if ($dataSize > 1024 * 1024) { // 1MB
                Monitor::increment('webtransport.stream.large_messages');
            }
        });

        // Stream closed
        $protocol->onStreamClosed(function(string $streamId, ?int $errorCode = null) {
            if (!isset($this->activeStreams[$streamId])) {
                return;
            }

            $streamData = $this->activeStreams[$streamId];
            $duration = microtime(true) - $streamData['opened_at'];
            
            Monitor::increment('webtransport.streams.closed');
            Monitor::gauge('webtransport.streams.active', $this->getActiveStreamCount() - 1);
            Monitor::timing('webtransport.stream.duration', $duration * 1000);
            
            if ($errorCode) {
                Monitor::increment('webtransport.streams.error');
            }

            // Finish stream span
            if (isset($streamData['span_id'])) {
                Trace::finishSpan($streamData['span_id'], [
                    'stream.duration_ms' => round($duration * 1000, 2),
                    'webtransport.bytes_sent' => $streamData['bytes_sent'],
                    'webtransport.bytes_received' => $streamData['bytes_received'],
                    'webtransport.messages_total' => $streamData['messages_sent'] + $streamData['messages_received'],
                    'stream.error_code' => $errorCode
                ]);
            }

            unset($this->activeStreams[$streamId]);

            $this->logger->debug('WebTransport stream closed', [
                'stream_id' => $streamId,
                'duration_ms' => round($duration * 1000, 2),
                'error_code' => $errorCode
            ]);
        });

        // Flow control events
        $protocol->onStreamFlowControlBlocked(function(string $streamId, int $blockedBytes) {
            if (isset($this->activeStreams[$streamId])) {
                $this->activeStreams[$streamId]['flow_control_blocked'] += $blockedBytes;
            }
            
            Monitor::increment('webtransport.stream.flow_control_blocked');
            Monitor::gauge('webtransport.stream.blocked_bytes', $blockedBytes);
        });
    }

    /**
     * Instrument datagram delivery
     */
    private function instrumentDatagramDelivery(WebTransportProtocol $protocol): void
    {
        if (!$this->config['track_datagram_delivery']) {
            return;
        }

        // Datagram sent
        $protocol->onDatagramSent(function(string $sessionId, string $data) {
            $datagramSize = strlen($data);
            
            // Update session metrics
            if (isset($this->activeSessions[$sessionId])) {
                $this->activeSessions[$sessionId]['datagrams_sent']++;
                $this->activeSessions[$sessionId]['bytes_sent'] += $datagramSize;
                $this->activeSessions[$sessionId]['last_activity'] = microtime(true);
            }
            
            // Record datagram metrics
            Monitor::increment('webtransport.datagrams.sent');
            Monitor::gauge('webtransport.datagram.size', $datagramSize);
            
            // Track datagram size distribution
            if ($datagramSize < 100) {
                Monitor::increment('webtransport.datagrams.size.small');
            } elseif ($datagramSize < 1000) {
                Monitor::increment('webtransport.datagrams.size.medium');
            } else {
                Monitor::increment('webtransport.datagrams.size.large');
            }

            // Create datagram span if sampling
            if ($this->shouldSample('datagram')) {
                Trace::span('webtransport.datagram.send', function() use ($sessionId, $data) {
                    return $this->sendDatagram($sessionId, $data);
                }, [
                    'session.id' => $sessionId,
                    'webtransport.datagram.size' => $datagramSize,
                    'protocol' => 'webtransport'
                ]);
            }
        });

        // Datagram received
        $protocol->onDatagramReceived(function(string $sessionId, string $data) {
            $datagramSize = strlen($data);
            
            // Update session metrics
            if (isset($this->activeSessions[$sessionId])) {
                $this->activeSessions[$sessionId]['datagrams_received']++;
                $this->activeSessions[$sessionId]['bytes_received'] += $datagramSize;
                $this->activeSessions[$sessionId]['last_activity'] = microtime(true);
            }
            
            Monitor::increment('webtransport.datagrams.received');
            Monitor::gauge('webtransport.datagram.received.size', $datagramSize);
        });

        // Datagram delivery failed
        $protocol->onDatagramDeliveryFailed(function(string $sessionId, string $data, string $reason) {
            Monitor::increment('webtransport.datagrams.failed');
            Monitor::increment("webtransport.datagrams.failed.{$reason}");

            $this->logger->warning('WebTransport datagram delivery failed', [
                'session_id' => $sessionId,
                'datagram_size' => strlen($data),
                'reason' => $reason
            ]);
        });
    }

    /**
     * Instrument QUIC transport metrics
     */
    private function instrumentQuicTransport(WebTransportProtocol $protocol): void
    {
        if (!$this->config['track_quic_transport']) {
            return;
        }

        // Connection migration
        $protocol->onConnectionMigration(function(string $sessionId, string $newPath, string $oldPath) {
            Monitor::increment('webtransport.quic.migrations');
            
            if (isset($this->activeSessions[$sessionId]['span_id'])) {
                Trace::event('webtransport.quic.migration', [
                    'session.id' => $sessionId,
                    'quic.old_path' => $oldPath,
                    'quic.new_path' => $newPath
                ]);
            }

            $this->logger->info('WebTransport QUIC connection migration', [
                'session_id' => $sessionId,
                'old_path' => $oldPath,
                'new_path' => $newPath
            ]);
        });

        // RTT measurements
        $protocol->onRttMeasurement(function(string $sessionId, float $rtt, float $rttVariation) {
            Monitor::gauge('webtransport.quic.rtt', $rtt * 1000); // Convert to ms
            Monitor::gauge('webtransport.quic.rtt_variation', $rttVariation * 1000);
            
            // Alert on high latency
            if ($rtt > 0.5) { // 500ms threshold
                Monitor::increment('webtransport.quic.high_latency');
            }
        });

        // Congestion control
        $protocol->onCongestionUpdate(function(string $sessionId, int $cwnd, int $bytesInFlight) {
            Monitor::gauge('webtransport.quic.congestion_window', $cwnd);
            Monitor::gauge('webtransport.quic.bytes_in_flight', $bytesInFlight);
            
            // Calculate congestion utilization
            $utilization = $cwnd > 0 ? ($bytesInFlight / $cwnd) * 100 : 0;
            Monitor::gauge('webtransport.quic.congestion_utilization', $utilization);
        });

        // Packet loss
        $protocol->onPacketLoss(function(string $sessionId, int $packetsLost, int $packetsTotal) {
            $lossRate = $packetsTotal > 0 ? ($packetsLost / $packetsTotal) * 100 : 0;
            
            Monitor::gauge('webtransport.quic.packet_loss_rate', $lossRate);
            Monitor::increment('webtransport.quic.packets_lost', $packetsLost);
            
            // Alert on high packet loss
            if ($lossRate > 2.0) { // 2% threshold
                Monitor::increment('webtransport.quic.high_packet_loss');
            }
        });
    }

    /**
     * Instrument performance metrics
     */
    private function instrumentPerformanceMetrics(WebTransportProtocol $protocol): void
    {
        if (!$this->config['performance_monitoring']) {
            return;
        }

        // Throughput measurement
        $protocol->onThroughputMeasurement(function(string $sessionId, float $sendRate, float $receiveRate) {
            Monitor::gauge('webtransport.throughput.send_rate', $sendRate);
            Monitor::gauge('webtransport.throughput.receive_rate', $receiveRate);
            
            // Calculate total throughput
            $totalThroughput = $sendRate + $receiveRate;
            Monitor::gauge('webtransport.throughput.total', $totalThroughput);
        });

        // Buffer utilization
        $protocol->onBufferUtilization(function(string $sessionId, float $sendBufferUtil, float $receiveBufferUtil) {
            Monitor::gauge('webtransport.buffer.send_utilization', $sendBufferUtil * 100);
            Monitor::gauge('webtransport.buffer.receive_utilization', $receiveBufferUtil * 100);
            
            // Alert on high buffer utilization
            if ($sendBufferUtil > 0.8 || $receiveBufferUtil > 0.8) {
                Monitor::increment('webtransport.buffer.high_utilization');
            }
        });
    }

    /**
     * Instrument reliability metrics
     */
    private function instrumentReliabilityMetrics(WebTransportProtocol $protocol): void
    {
        if (!$this->config['reliability_monitoring']) {
            return;
        }

        // Connection establishment time
        $protocol->onConnectionEstablishmentTime(function(string $sessionId, float $establishmentTime) {
            Monitor::timing('webtransport.connection.establishment_time', $establishmentTime * 1000);
            
            // Alert on slow establishment
            if ($establishmentTime > 2.0) { // 2 second threshold
                Monitor::increment('webtransport.connection.slow_establishment');
            }
        });

        // Stream establishment time
        $protocol->onStreamEstablishmentTime(function(string $streamId, float $establishmentTime) {
            Monitor::timing('webtransport.stream.establishment_time', $establishmentTime * 1000);
        });

        // Connection stability
        $this->scheduleStabilityChecks();
    }

    /**
     * Monitor session security
     */
    private function monitorSessionSecurity(array $sessionInfo): void
    {
        $clientAddress = $sessionInfo['client_address'] ?? '';
        $protocolVersion = $sessionInfo['protocol_version'] ?? '';
        
        // Check for suspicious patterns
        $suspiciousPatterns = [
            'rapid_sessions' => $this->checkRapidSessions($clientAddress),
            'invalid_protocol_version' => $this->checkInvalidProtocolVersion($protocolVersion),
            'blocked_client' => $this->checkBlockedClient($clientAddress)
        ];

        foreach ($suspiciousPatterns as $pattern => $detected) {
            if ($detected) {
                Monitor::security("webtransport_{$pattern}", [
                    'client_address' => $clientAddress,
                    'protocol_version' => $protocolVersion,
                    'severity' => $this->getSecuritySeverity($pattern)
                ]);
            }
        }
    }

    /**
     * Schedule periodic stability checks
     */
    private function scheduleStabilityChecks(): void
    {
        \Revolt\EventLoop::repeat(300000, function() { // Every 5 minutes
            try {
                $activeSessionsCount = count($this->activeSessions);
                $activeStreamsCount = count($this->activeStreams);
                
                Monitor::gauge('webtransport.stability.active_sessions', $activeSessionsCount);
                Monitor::gauge('webtransport.stability.active_streams', $activeStreamsCount);
                
                // Check for stale sessions
                $staleCount = 0;
                $currentTime = microtime(true);
                
                foreach ($this->activeSessions as $session) {
                    $timeSinceActivity = $currentTime - $session['last_activity'];
                    if ($timeSinceActivity > 1800) { // 30 minutes
                        $staleCount++;
                    }
                }
                
                Monitor::gauge('webtransport.stability.stale_sessions', $staleCount);
                
            } catch (\Throwable $e) {
                $this->logger->error('Failed to perform WebTransport stability check', [
                    'error' => $e->getMessage()
                ]);
            }
        });
    }

    /**
     * Helper methods
     */
    private function shouldSample(string $eventType): bool
    {
        $rate = $this->config['sample_rate'];
        
        // Adjust sampling rate based on event type
        switch ($eventType) {
            case 'session':
                $rate = $this->config['sample_sessions'] ? $rate : 0;
                break;
            case 'stream':
                $rate = $this->config['sample_streams'] ? $rate * 0.5 : 0;
                break;
            case 'datagram':
                $rate = $this->config['sample_datagrams'] ? $rate * 0.1 : 0; // Lower rate for datagrams
                break;
        }
        
        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function getActiveSessionCount(): int
    {
        return count($this->activeSessions);
    }

    private function getActiveStreamCount(): int
    {
        return count($this->activeStreams);
    }

    // Placeholder methods for actual protocol operations
    private function sendDatagram(string $sessionId, string $data): bool
    {
        // Actual datagram sending logic would go here
        return true;
    }

    // Security check methods (simplified implementations)
    private function checkRapidSessions(string $clientAddress): bool
    {
        // Implementation would check session creation rate from this address
        return false;
    }

    private function checkInvalidProtocolVersion(string $protocolVersion): bool
    {
        $validVersions = ['draft-02', 'draft-03', 'draft-04'];
        return !in_array($protocolVersion, $validVersions);
    }

    private function checkBlockedClient(string $clientAddress): bool
    {
        // Implementation would check against blocked client list
        return false;
    }

    private function getSecuritySeverity(string $pattern): string
    {
        $severityMap = [
            'rapid_sessions' => 'high',
            'invalid_protocol_version' => 'medium',
            'blocked_client' => 'critical'
        ];

        return $severityMap[$pattern] ?? 'medium';
    }
}