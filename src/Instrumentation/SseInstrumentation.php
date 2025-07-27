<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Instrumentation;

use HighPerApp\HighPer\Monitoring\Facades\Monitor;
use HighPerApp\HighPer\Tracing\Facades\Trace;
use HighPerApp\HighPer\Realtime\Protocols\SseProtocol;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Server-Sent Events (SSE) Protocol Auto-Instrumentation
 * 
 * Provides automatic monitoring and tracing for SSE connections,
 * event streams, and client management with real-time metrics.
 */
class SseInstrumentation
{
    private LoggerInterface $logger;
    private array $config;
    private array $activeConnections = [];
    private array $eventStreams = [];
    private array $channelMetrics = [];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'enabled' => true,
            'sample_connections' => true,
            'sample_events' => true,
            'sample_rate' => 0.15, // 15% sampling for SSE
            'track_connection_lifecycle' => true,
            'track_event_delivery' => true,
            'track_channel_metrics' => true,
            'track_client_reconnections' => true,
            'security_monitoring' => true,
            'performance_monitoring' => true
        ], $config);
    }

    /**
     * Instrument SSE protocol
     */
    public function instrument(SseProtocol $protocol): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $this->instrumentConnectionLifecycle($protocol);
        $this->instrumentEventDelivery($protocol);
        $this->instrumentChannelManagement($protocol);
        $this->instrumentClientReconnections($protocol);
        $this->instrumentPerformanceMetrics($protocol);

        $this->logger->info('SSE instrumentation enabled', [
            'sample_rate' => $this->config['sample_rate'],
            'features' => array_keys(array_filter($this->config))
        ]);
    }

    /**
     * Instrument SSE connection lifecycle
     */
    private function instrumentConnectionLifecycle(SseProtocol $protocol): void
    {
        if (!$this->config['track_connection_lifecycle']) {
            return;
        }

        // Connection established
        $protocol->onConnect(function(ServerRequestInterface $request, string $connectionId) {
            $clientIp = $this->extractClientIp($request);
            $userAgent = $request->getHeaderLine('user-agent');
            $channel = $request->getQueryParams()['channel'] ?? 'default';
            $lastEventId = $request->getHeaderLine('last-event-id');
            
            // Record connection metrics
            Monitor::increment('sse.connections.total');
            Monitor::gauge('sse.connections.active', $this->getActiveConnectionCount() + 1);
            Monitor::increment("sse.connections.channel.{$channel}");
            
            // Track connection details
            $this->activeConnections[$connectionId] = [
                'id' => $connectionId,
                'client_ip' => $clientIp,
                'user_agent' => $userAgent,
                'channel' => $channel,
                'connected_at' => microtime(true),
                'last_event_id' => $lastEventId,
                'events_sent' => 0,
                'bytes_sent' => 0,
                'reconnection_count' => 0,
                'last_activity' => microtime(true)
            ];

            // Create connection span if sampling
            if ($this->shouldSample('connection')) {
                $span = Trace::startSpan('sse.connection', [
                    'connection.id' => $connectionId,
                    'connection.client_ip' => $clientIp,
                    'sse.channel' => $channel,
                    'sse.last_event_id' => $lastEventId,
                    'protocol' => 'sse',
                    'span.kind' => 'server'
                ]);
                
                $this->activeConnections[$connectionId]['span_id'] = $span;
            }

            // Update channel metrics
            $this->updateChannelMetrics($channel, 'connect');

            // Security monitoring
            if ($this->config['security_monitoring']) {
                $this->monitorConnectionSecurity($request, $connectionId);
            }

            $this->logger->debug('SSE connection established', [
                'connection_id' => $connectionId,
                'client_ip' => $clientIp,
                'channel' => $channel,
                'last_event_id' => $lastEventId
            ]);
        });

        // Connection closed
        $protocol->onDisconnect(function(string $connectionId, ?string $reason = null) {
            if (!isset($this->activeConnections[$connectionId])) {
                return;
            }

            $connectionData = $this->activeConnections[$connectionId];
            $duration = microtime(true) - $connectionData['connected_at'];
            $channel = $connectionData['channel'];
            
            // Record disconnection metrics
            Monitor::increment('sse.disconnections.total');
            Monitor::gauge('sse.connections.active', $this->getActiveConnectionCount() - 1);
            Monitor::timing('sse.connection.duration', $duration * 1000);
            
            if ($reason) {
                Monitor::increment("sse.disconnections.reason.{$reason}");
            }

            // Record connection summary
            Monitor::gauge('sse.connection.events_sent', $connectionData['events_sent']);
            Monitor::gauge('sse.connection.bytes_sent', $connectionData['bytes_sent']);
            Monitor::gauge('sse.connection.reconnections', $connectionData['reconnection_count']);

            // Finish connection span
            if (isset($connectionData['span_id'])) {
                Trace::finishSpan($connectionData['span_id'], [
                    'connection.duration_ms' => round($duration * 1000, 2),
                    'sse.events_sent' => $connectionData['events_sent'],
                    'sse.bytes_sent' => $connectionData['bytes_sent'],
                    'sse.reconnections' => $connectionData['reconnection_count'],
                    'connection.close_reason' => $reason
                ]);
            }

            // Update channel metrics
            $this->updateChannelMetrics($channel, 'disconnect');

            // Clean up
            unset($this->activeConnections[$connectionId]);

            $this->logger->debug('SSE connection closed', [
                'connection_id' => $connectionId,
                'duration_ms' => round($duration * 1000, 2),
                'events_sent' => $connectionData['events_sent'],
                'reason' => $reason
            ]);
        });
    }

    /**
     * Instrument SSE event delivery
     */
    private function instrumentEventDelivery(SseProtocol $protocol): void
    {
        if (!$this->config['track_event_delivery']) {
            return;
        }

        // Event sent to single connection
        $protocol->onEventSent(function(string $connectionId, array $event) {
            $eventData = $this->serializeEvent($event);
            $eventSize = strlen($eventData);
            $eventType = $event['type'] ?? 'message';
            $eventId = $event['id'] ?? null;
            
            // Update connection metrics
            if (isset($this->activeConnections[$connectionId])) {
                $this->activeConnections[$connectionId]['events_sent']++;
                $this->activeConnections[$connectionId]['bytes_sent'] += $eventSize;
                $this->activeConnections[$connectionId]['last_activity'] = microtime(true);
            }

            // Record event metrics
            Monitor::increment('sse.events.sent.total');
            Monitor::increment("sse.events.type.{$eventType}");
            Monitor::gauge('sse.event.size', $eventSize);

            // Create event span if sampling
            if ($this->shouldSample('event')) {
                return Trace::span('sse.event.send', function() use ($connectionId, $event) {
                    return $this->deliverEvent($connectionId, $event);
                }, [
                    'connection.id' => $connectionId,
                    'sse.event.type' => $eventType,
                    'sse.event.id' => $eventId,
                    'sse.event.size' => $eventSize
                ]);
            }

            // Fast path without tracing
            return $this->deliverEvent($connectionId, $event);
        });

        // Event broadcast to channel
        $protocol->onEventBroadcast(function(string $channel, array $event, array $connectionIds) {
            $eventType = $event['type'] ?? 'message';
            $recipientCount = count($connectionIds);
            
            // Record broadcast metrics
            Monitor::increment('sse.broadcasts.total');
            Monitor::increment("sse.broadcasts.channel.{$channel}");
            Monitor::gauge('sse.broadcast.recipients', $recipientCount);
            
            // Create broadcast span if sampling
            if ($this->shouldSample('broadcast')) {
                return Trace::span('sse.event.broadcast', function() use ($channel, $event, $connectionIds) {
                    return $this->broadcastEvent($channel, $event, $connectionIds);
                }, [
                    'sse.channel' => $channel,
                    'sse.event.type' => $eventType,
                    'sse.broadcast.recipients' => $recipientCount
                ]);
            }

            // Fast path without tracing
            return $this->broadcastEvent($channel, $event, $connectionIds);
        });

        // Event delivery failed
        $protocol->onEventDeliveryFailed(function(string $connectionId, array $event, \Throwable $error) {
            $eventType = $event['type'] ?? 'message';
            
            Monitor::increment('sse.events.failed.total');
            Monitor::increment("sse.events.failed.type.{$eventType}");
            
            Trace::error($error);

            $this->logger->warning('SSE event delivery failed', [
                'connection_id' => $connectionId,
                'event_type' => $eventType,
                'error' => $error->getMessage()
            ]);
        });
    }

    /**
     * Instrument channel management
     */
    private function instrumentChannelManagement(SseProtocol $protocol): void
    {
        if (!$this->config['track_channel_metrics']) {
            return;
        }

        // Channel created
        $protocol->onChannelCreated(function(string $channel) {
            $this->channelMetrics[$channel] = [
                'name' => $channel,
                'created_at' => microtime(true),
                'connections' => 0,
                'events_sent' => 0,
                'bytes_sent' => 0
            ];

            Monitor::increment('sse.channels.created');
            Monitor::gauge('sse.channels.active', count($this->channelMetrics));

            $this->logger->debug('SSE channel created', ['channel' => $channel]);
        });

        // Channel deleted
        $protocol->onChannelDeleted(function(string $channel) {
            if (isset($this->channelMetrics[$channel])) {
                $channelData = $this->channelMetrics[$channel];
                $lifetime = microtime(true) - $channelData['created_at'];
                
                Monitor::increment('sse.channels.deleted');
                Monitor::gauge('sse.channels.active', count($this->channelMetrics) - 1);
                Monitor::timing('sse.channel.lifetime', $lifetime * 1000);
                
                unset($this->channelMetrics[$channel]);
            }

            $this->logger->debug('SSE channel deleted', ['channel' => $channel]);
        });
    }

    /**
     * Instrument client reconnections
     */
    private function instrumentClientReconnections(SseProtocol $protocol): void
    {
        if (!$this->config['track_client_reconnections']) {
            return;
        }

        // Client reconnection detected
        $protocol->onClientReconnection(function(string $connectionId, string $lastEventId) {
            if (isset($this->activeConnections[$connectionId])) {
                $this->activeConnections[$connectionId]['reconnection_count']++;
            }

            Monitor::increment('sse.reconnections.total');
            
            // Track reconnection patterns
            if (!empty($lastEventId)) {
                Monitor::increment('sse.reconnections.with_last_event_id');
            } else {
                Monitor::increment('sse.reconnections.without_last_event_id');
            }

            $this->logger->debug('SSE client reconnection', [
                'connection_id' => $connectionId,
                'last_event_id' => $lastEventId
            ]);
        });

        // Reconnection gap detected (missed events)
        $protocol->onReconnectionGap(function(string $connectionId, int $missedEvents) {
            Monitor::increment('sse.reconnections.gaps');
            Monitor::gauge('sse.reconnection.missed_events', $missedEvents);
            
            // Alert on large gaps
            if ($missedEvents > 10) {
                Monitor::increment('sse.reconnections.large_gaps');
            }

            $this->logger->info('SSE reconnection gap detected', [
                'connection_id' => $connectionId,
                'missed_events' => $missedEvents
            ]);
        });
    }

    /**
     * Instrument performance metrics
     */
    private function instrumentPerformanceMetrics(SseProtocol $protocol): void
    {
        if (!$this->config['performance_monitoring']) {
            return;
        }

        // Event queue size monitoring
        $protocol->onEventQueueSize(function(string $connectionId, int $queueSize) {
            Monitor::gauge('sse.event_queue.size', $queueSize);
            
            // Alert on large queues
            if ($queueSize > 100) {
                Monitor::increment('sse.event_queue.large');
            }
        });

        // Delivery latency measurement
        $protocol->onEventDeliveryLatency(function(string $connectionId, float $latency) {
            Monitor::timing('sse.event.delivery_latency', $latency * 1000);
            
            // Alert on high latency
            if ($latency > 1.0) { // 1 second threshold
                Monitor::increment('sse.events.high_latency');
            }
        });

        // Connection health check
        $this->scheduleConnectionHealthCheck();
    }

    /**
     * Monitor connection security
     */
    private function monitorConnectionSecurity(ServerRequestInterface $request, string $connectionId): void
    {
        $clientIp = $this->extractClientIp($request);
        $userAgent = $request->getHeaderLine('user-agent');
        $origin = $request->getHeaderLine('origin');
        $referer = $request->getHeaderLine('referer');
        
        // Check for suspicious patterns
        $suspiciousPatterns = [
            'rapid_connections' => $this->checkRapidConnections($clientIp),
            'suspicious_user_agent' => $this->checkSuspiciousUserAgent($userAgent),
            'invalid_origin' => $this->checkInvalidOrigin($origin),
            'missing_headers' => $this->checkMissingHeaders($request),
            'rate_limit_exceeded' => $this->checkRateLimit($clientIp)
        ];

        foreach ($suspiciousPatterns as $pattern => $detected) {
            if ($detected) {
                Monitor::security("sse_{$pattern}", [
                    'connection_id' => $connectionId,
                    'client_ip' => $clientIp,
                    'user_agent' => $userAgent,
                    'origin' => $origin,
                    'severity' => $this->getSecuritySeverity($pattern)
                ]);
            }
        }
    }

    /**
     * Schedule periodic connection health checks
     */
    private function scheduleConnectionHealthCheck(): void
    {
        \Revolt\EventLoop::repeat(60000, function() { // Every minute
            try {
                $activeCount = count($this->activeConnections);
                $staleCount = 0;
                $currentTime = microtime(true);
                
                foreach ($this->activeConnections as $connection) {
                    $timeSinceActivity = $currentTime - $connection['last_activity'];
                    if ($timeSinceActivity > 300) { // 5 minutes
                        $staleCount++;
                    }
                }
                
                Monitor::gauge('sse.connections.health.active', $activeCount);
                Monitor::gauge('sse.connections.health.stale', $staleCount);
                
                if ($staleCount > 0) {
                    $this->logger->warning('Stale SSE connections detected', [
                        'stale_count' => $staleCount,
                        'active_count' => $activeCount
                    ]);
                }
                
            } catch (\Throwable $e) {
                $this->logger->error('Failed to perform SSE health check', [
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
            case 'connection':
                $rate = $this->config['sample_connections'] ? $rate : 0;
                break;
            case 'event':
                $rate = $this->config['sample_events'] ? $rate * 0.1 : 0; // Lower rate for events
                break;
            case 'broadcast':
                $rate = $rate * 2; // Higher rate for broadcasts
                break;
        }
        
        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function extractClientIp(ServerRequestInterface $request): string
    {
        $headers = ['x-forwarded-for', 'x-real-ip', 'cf-connecting-ip'];
        
        foreach ($headers as $header) {
            $ip = $request->getHeaderLine($header);
            if (!empty($ip)) {
                return explode(',', $ip)[0];
            }
        }
        
        return $request->getAttribute('client_ip', 'unknown');
    }

    private function serializeEvent(array $event): string
    {
        $output = '';
        
        if (isset($event['id'])) {
            $output .= "id: {$event['id']}\n";
        }
        
        if (isset($event['type'])) {
            $output .= "event: {$event['type']}\n";
        }
        
        if (isset($event['retry'])) {
            $output .= "retry: {$event['retry']}\n";
        }
        
        if (isset($event['data'])) {
            $lines = explode("\n", $event['data']);
            foreach ($lines as $line) {
                $output .= "data: {$line}\n";
            }
        }
        
        $output .= "\n";
        
        return $output;
    }

    private function updateChannelMetrics(string $channel, string $action): void
    {
        if (!isset($this->channelMetrics[$channel])) {
            return;
        }

        if ($action === 'connect') {
            $this->channelMetrics[$channel]['connections']++;
        } elseif ($action === 'disconnect') {
            $this->channelMetrics[$channel]['connections']--;
        }

        Monitor::gauge("sse.channel.{$channel}.connections", 
            $this->channelMetrics[$channel]['connections']);
    }

    private function getActiveConnectionCount(): int
    {
        return count($this->activeConnections);
    }

    // Placeholder methods for actual message processing
    private function deliverEvent(string $connectionId, array $event)
    {
        // Actual event delivery logic would go here
        return true;
    }

    private function broadcastEvent(string $channel, array $event, array $connectionIds)
    {
        // Actual broadcast logic would go here
        return count($connectionIds);
    }

    // Security check methods (simplified implementations)
    private function checkRapidConnections(string $clientIp): bool
    {
        // Implementation would check connection rate from this IP
        return false;
    }

    private function checkSuspiciousUserAgent(string $userAgent): bool
    {
        $suspicious = ['bot', 'scanner', 'probe', 'test'];
        foreach ($suspicious as $pattern) {
            if (str_contains(strtolower($userAgent), $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function checkInvalidOrigin(string $origin): bool
    {
        // Implementation would check against allowed origins
        return false;
    }

    private function checkMissingHeaders(ServerRequestInterface $request): bool
    {
        $requiredHeaders = ['accept', 'cache-control'];
        foreach ($requiredHeaders as $header) {
            if (empty($request->getHeaderLine($header))) {
                return true;
            }
        }
        return false;
    }

    private function checkRateLimit(string $clientIp): bool
    {
        // Implementation would check rate limiting
        return false;
    }

    private function getSecuritySeverity(string $pattern): string
    {
        $severityMap = [
            'rapid_connections' => 'high',
            'suspicious_user_agent' => 'low',
            'invalid_origin' => 'medium',
            'missing_headers' => 'low',
            'rate_limit_exceeded' => 'medium'
        ];

        return $severityMap[$pattern] ?? 'medium';
    }
}