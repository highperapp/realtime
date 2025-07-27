<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Instrumentation;

use HighPerApp\HighPer\Monitoring\Facades\Monitor;
use HighPerApp\HighPer\Tracing\Facades\Trace;
use HighPerApp\HighPer\Realtime\Protocols\WebSocketProtocol;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * WebSocket Protocol Auto-Instrumentation
 * 
 * Provides automatic monitoring and tracing for WebSocket connections,
 * messages, and events with minimal performance overhead.
 */
class WebSocketInstrumentation
{
    private LoggerInterface $logger;
    private array $config;
    private array $activeConnections = [];
    private array $connectionMetrics = [];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'enabled' => true,
            'sample_connections' => true,
            'sample_messages' => true,
            'sample_rate' => 0.1, // 10% sampling by default
            'track_connection_lifecycle' => true,
            'track_message_metrics' => true,
            'track_performance' => true,
            'security_monitoring' => true
        ], $config);
    }

    /**
     * Instrument WebSocket protocol
     */
    public function instrument(WebSocketProtocol $protocol): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $this->instrumentConnectionEvents($protocol);
        $this->instrumentMessageEvents($protocol);
        $this->instrumentErrorEvents($protocol);
        $this->instrumentPerformanceEvents($protocol);

        $this->logger->info('WebSocket instrumentation enabled', [
            'sample_rate' => $this->config['sample_rate'],
            'features' => array_keys(array_filter($this->config))
        ]);
    }

    /**
     * Instrument connection lifecycle events
     */
    private function instrumentConnectionEvents(WebSocketProtocol $protocol): void
    {
        if (!$this->config['track_connection_lifecycle']) {
            return;
        }

        // Connection established
        $protocol->onConnect(function($connection) {
            $connectionId = $connection->getId();
            $remoteAddress = $connection->getRemoteAddress();
            
            // Record connection metrics
            Monitor::increment('websocket.connections.total');
            Monitor::gauge('websocket.connections.active', $this->getActiveConnectionCount() + 1);
            
            // Track connection details
            $this->activeConnections[$connectionId] = [
                'id' => $connectionId,
                'remote_address' => $remoteAddress,
                'connected_at' => microtime(true),
                'message_count' => 0,
                'bytes_sent' => 0,
                'bytes_received' => 0,
                'last_activity' => microtime(true)
            ];

            // Create connection span if sampling
            if ($this->shouldSample('connection')) {
                $span = Trace::startSpan('websocket.connection', [
                    'connection.id' => $connectionId,
                    'connection.remote_address' => $remoteAddress,
                    'protocol' => 'websocket',
                    'span.kind' => 'server'
                ]);
                
                $this->activeConnections[$connectionId]['span_id'] = $span;
            }

            // Security monitoring
            if ($this->config['security_monitoring']) {
                $this->monitorConnectionSecurity($connection);
            }

            $this->logger->debug('WebSocket connection established', [
                'connection_id' => $connectionId,
                'remote_address' => $remoteAddress
            ]);
        });

        // Connection closed
        $protocol->onDisconnect(function($connection, $code, $reason) {
            $connectionId = $connection->getId();
            
            if (!isset($this->activeConnections[$connectionId])) {
                return;
            }

            $connectionData = $this->activeConnections[$connectionId];
            $duration = microtime(true) - $connectionData['connected_at'];
            
            // Record disconnection metrics
            Monitor::increment('websocket.disconnections.total');
            Monitor::gauge('websocket.connections.active', $this->getActiveConnectionCount() - 1);
            Monitor::timing('websocket.connection.duration', $duration * 1000); // Convert to ms
            
            // Record connection summary metrics
            Monitor::gauge('websocket.connection.messages_total', $connectionData['message_count']);
            Monitor::gauge('websocket.connection.bytes_sent', $connectionData['bytes_sent']);
            Monitor::gauge('websocket.connection.bytes_received', $connectionData['bytes_received']);

            // Close connection span
            if (isset($connectionData['span_id'])) {
                Trace::finishSpan($connectionData['span_id'], [
                    'connection.duration_ms' => round($duration * 1000, 2),
                    'connection.message_count' => $connectionData['message_count'],
                    'connection.bytes_total' => $connectionData['bytes_sent'] + $connectionData['bytes_received'],
                    'connection.close_code' => $code,
                    'connection.close_reason' => $reason
                ]);
            }

            // Clean up
            unset($this->activeConnections[$connectionId]);

            $this->logger->debug('WebSocket connection closed', [
                'connection_id' => $connectionId,
                'duration_ms' => round($duration * 1000, 2),
                'message_count' => $connectionData['message_count'],
                'close_code' => $code,
                'close_reason' => $reason
            ]);
        });
    }

    /**
     * Instrument message events
     */
    private function instrumentMessageEvents(WebSocketProtocol $protocol): void
    {
        if (!$this->config['track_message_metrics']) {
            return;
        }

        // Message received
        $protocol->onMessage(function($connection, $message) {
            $connectionId = $connection->getId();
            $messageSize = strlen($message);
            $messageType = $this->detectMessageType($message);
            
            // Update connection metrics
            if (isset($this->activeConnections[$connectionId])) {
                $this->activeConnections[$connectionId]['message_count']++;
                $this->activeConnections[$connectionId]['bytes_received'] += $messageSize;
                $this->activeConnections[$connectionId]['last_activity'] = microtime(true);
            }

            // Record message metrics
            Monitor::increment('websocket.messages.received.total');
            Monitor::gauge('websocket.message.size', $messageSize);
            
            if ($messageType) {
                Monitor::increment("websocket.messages.type.{$messageType}");
            }

            // Create message span if sampling
            if ($this->shouldSample('message')) {
                return Trace::span('websocket.message.received', function() use ($connection, $message) {
                    return $this->processMessage($connection, $message);
                }, [
                    'connection.id' => $connectionId,
                    'message.size' => $messageSize,
                    'message.type' => $messageType,
                    'protocol' => 'websocket'
                ]);
            }

            // Fast path without tracing
            return $this->processMessage($connection, $message);
        });

        // Message sent (if the protocol supports it)
        if (method_exists($protocol, 'onMessageSent')) {
            $protocol->onMessageSent(function($connection, $message) {
                $connectionId = $connection->getId();
                $messageSize = strlen($message);
                
                // Update connection metrics
                if (isset($this->activeConnections[$connectionId])) {
                    $this->activeConnections[$connectionId]['bytes_sent'] += $messageSize;
                }

                // Record message metrics
                Monitor::increment('websocket.messages.sent.total');
                Monitor::gauge('websocket.message.sent.size', $messageSize);
            });
        }
    }

    /**
     * Instrument error events
     */
    private function instrumentErrorEvents(WebSocketProtocol $protocol): void
    {
        $protocol->onError(function($connection, $error) {
            $connectionId = $connection->getId();
            
            // Record error metrics
            Monitor::increment('websocket.errors.total');
            Monitor::increment('websocket.errors.' . $this->categorizeError($error));
            
            // Record error in tracing
            Trace::error($error);
            
            // Security monitoring for suspicious errors
            if ($this->config['security_monitoring'] && $this->isSuspiciousError($error)) {
                Monitor::security('websocket_suspicious_error', [
                    'connection_id' => $connectionId,
                    'error_type' => get_class($error),
                    'error_message' => $error->getMessage(),
                    'severity' => 'medium'
                ]);
            }

            $this->logger->error('WebSocket error occurred', [
                'connection_id' => $connectionId,
                'error_type' => get_class($error),
                'error_message' => $error->getMessage(),
                'error_code' => $error->getCode()
            ]);
        });
    }

    /**
     * Instrument performance events
     */
    private function instrumentPerformanceEvents(WebSocketProtocol $protocol): void
    {
        if (!$this->config['track_performance']) {
            return;
        }

        // Track frame processing time (if supported)
        if (method_exists($protocol, 'onFrameProcessed')) {
            $protocol->onFrameProcessed(function($duration, $frameSize) {
                Monitor::timing('websocket.frame.processing_time', $duration * 1000);
                Monitor::gauge('websocket.frame.size', $frameSize);
                
                // Alert on slow frame processing
                if ($duration > 0.1) { // 100ms threshold
                    Monitor::increment('websocket.frames.slow');
                }
            });
        }

        // Track connection pool metrics
        if (method_exists($protocol, 'getConnectionPoolMetrics')) {
            $this->schedulePeriodicMetrics($protocol);
        }
    }

    /**
     * Monitor connection security
     */
    private function monitorConnectionSecurity($connection): void
    {
        $remoteAddress = $connection->getRemoteAddress();
        $userAgent = $connection->getHeader('User-Agent') ?? '';
        
        // Check for suspicious patterns
        $suspiciousPatterns = [
            'rapid_connections' => $this->checkRapidConnections($remoteAddress),
            'suspicious_user_agent' => $this->checkSuspiciousUserAgent($userAgent),
            'blocked_ip' => $this->checkBlockedIp($remoteAddress)
        ];

        foreach ($suspiciousPatterns as $pattern => $detected) {
            if ($detected) {
                Monitor::security("websocket_{$pattern}", [
                    'remote_address' => $remoteAddress,
                    'user_agent' => $userAgent,
                    'severity' => $this->getSecuritySeverity($pattern)
                ]);
            }
        }
    }

    /**
     * Process message (placeholder for actual message processing)
     */
    private function processMessage($connection, $message)
    {
        // This would be the actual message processing logic
        // For instrumentation purposes, we just return the message
        return $message;
    }

    /**
     * Detect message type from content
     */
    private function detectMessageType($message): ?string
    {
        if (is_string($message)) {
            $decoded = json_decode($message, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['type'])) {
                return $decoded['type'];
            }
        }
        
        return null;
    }

    /**
     * Categorize error for metrics
     */
    private function categorizeError(\Throwable $error): string
    {
        $errorClass = get_class($error);
        
        if (str_contains($errorClass, 'Connection')) {
            return 'connection';
        } elseif (str_contains($errorClass, 'Protocol')) {
            return 'protocol';
        } elseif (str_contains($errorClass, 'Parse')) {
            return 'parsing';
        } else {
            return 'general';
        }
    }

    /**
     * Check if error is suspicious from security perspective
     */
    private function isSuspiciousError(\Throwable $error): bool
    {
        $suspiciousPatterns = [
            'payload too large',
            'invalid frame',
            'connection flood',
            'rate limit'
        ];

        $message = strtolower($error->getMessage());
        foreach ($suspiciousPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schedule periodic metrics collection
     */
    private function schedulePeriodicMetrics(WebSocketProtocol $protocol): void
    {
        \Revolt\EventLoop::repeat(30000, function() use ($protocol) { // Every 30 seconds
            try {
                $metrics = $protocol->getConnectionPoolMetrics();
                
                Monitor::gauge('websocket.pool.active_connections', $metrics['active_connections'] ?? 0);
                Monitor::gauge('websocket.pool.pending_connections', $metrics['pending_connections'] ?? 0);
                Monitor::gauge('websocket.pool.memory_usage', $metrics['memory_usage'] ?? 0);
                
            } catch (\Throwable $e) {
                $this->logger->error('Failed to collect periodic WebSocket metrics', [
                    'error' => $e->getMessage()
                ]);
            }
        });
    }

    /**
     * Check if we should sample this event
     */
    private function shouldSample(string $eventType): bool
    {
        $rate = $this->config['sample_rate'];
        
        // Adjust sampling rate based on event type
        switch ($eventType) {
            case 'connection':
                $rate = $this->config['sample_connections'] ? $rate : 0;
                break;
            case 'message':
                $rate = $this->config['sample_messages'] ? $rate * 0.1 : 0; // Lower rate for messages
                break;
        }
        
        return mt_rand() / mt_getrandmax() < $rate;
    }

    /**
     * Get active connection count
     */
    private function getActiveConnectionCount(): int
    {
        return count($this->activeConnections);
    }

    /**
     * Security check methods (placeholders for actual implementations)
     */
    private function checkRapidConnections(string $remoteAddress): bool
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

    private function checkBlockedIp(string $remoteAddress): bool
    {
        // Implementation would check against blocked IP list
        return false;
    }

    private function getSecuritySeverity(string $pattern): string
    {
        $severityMap = [
            'rapid_connections' => 'high',
            'suspicious_user_agent' => 'low',
            'blocked_ip' => 'critical'
        ];

        return $severityMap[$pattern] ?? 'medium';
    }
}