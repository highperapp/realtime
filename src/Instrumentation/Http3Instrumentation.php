<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Instrumentation;

use HighPerApp\HighPer\Monitoring\Facades\Monitor;
use HighPerApp\HighPer\Tracing\Facades\Trace;
use HighPerApp\HighPer\Realtime\Protocols\Http3Protocol;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP/3 Protocol Auto-Instrumentation
 * 
 * Provides automatic monitoring and tracing for HTTP/3 requests,
 * streams, server push, and QUIC connection management.
 */
class Http3Instrumentation
{
    private LoggerInterface $logger;
    private array $config;
    private array $activeRequests = [];
    private array $streamMetrics = [];
    private array $connectionMetrics = [];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'enabled' => true,
            'sample_requests' => true,
            'sample_streams' => true,
            'sample_rate' => 0.2, // 20% sampling for HTTP/3
            'track_quic_metrics' => true,
            'track_server_push' => true,
            'track_stream_multiplexing' => true,
            'track_connection_migration' => true,
            'security_monitoring' => true,
            'performance_optimization' => true
        ], $config);
    }

    /**
     * Instrument HTTP/3 protocol
     */
    public function instrument(Http3Protocol $protocol): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $this->instrumentRequestLifecycle($protocol);
        $this->instrumentStreamManagement($protocol);
        $this->instrumentServerPush($protocol);
        $this->instrumentQuicConnection($protocol);
        $this->instrumentPerformanceMetrics($protocol);

        $this->logger->info('HTTP/3 instrumentation enabled', [
            'sample_rate' => $this->config['sample_rate'],
            'features' => array_keys(array_filter($this->config))
        ]);
    }

    /**
     * Instrument HTTP/3 request lifecycle
     */
    private function instrumentRequestLifecycle(Http3Protocol $protocol): void
    {
        // Request received
        $protocol->onRequest(function(ServerRequestInterface $request) {
            $requestId = $this->generateRequestId();
            $startTime = microtime(true);
            
            // Extract request attributes
            $method = $request->getMethod();
            $uri = (string) $request->getUri();
            $contentLength = $request->getHeaderLine('content-length') ?: 0;
            $userAgent = $request->getHeaderLine('user-agent');
            $clientIp = $this->extractClientIp($request);
            
            // Record request metrics
            Monitor::increment('http3.requests.total');
            Monitor::increment("http3.requests.method.{$method}");
            Monitor::gauge('http3.request.content_length', (int) $contentLength);
            
            // Track active requests
            $this->activeRequests[$requestId] = [
                'id' => $requestId,
                'method' => $method,
                'uri' => $uri,
                'start_time' => $startTime,
                'client_ip' => $clientIp,
                'user_agent' => $userAgent,
                'content_length' => (int) $contentLength,
                'stream_id' => $request->getAttribute('stream_id'),
                'connection_id' => $request->getAttribute('connection_id')
            ];

            // Create request span if sampling
            if ($this->shouldSample('request')) {
                $span = Trace::startSpan('http3.request', [
                    'http.method' => $method,
                    'http.url' => $uri,
                    'http.scheme' => 'https',
                    'http.user_agent' => $userAgent,
                    'http.request_content_length' => (int) $contentLength,
                    'http3.stream_id' => $request->getAttribute('stream_id'),
                    'http3.connection_id' => $request->getAttribute('connection_id'),
                    'protocol' => 'http3',
                    'span.kind' => 'server'
                ]);
                
                $this->activeRequests[$requestId]['span_id'] = $span;
            }

            // Security monitoring
            if ($this->config['security_monitoring']) {
                $this->monitorRequestSecurity($request);
            }

            $this->logger->debug('HTTP/3 request started', [
                'request_id' => $requestId,
                'method' => $method,
                'uri' => $uri,
                'client_ip' => $clientIp
            ]);

            return $requestId;
        });

        // Response sent
        $protocol->onResponse(function($requestId, ResponseInterface $response) {
            if (!isset($this->activeRequests[$requestId])) {
                return;
            }

            $requestData = $this->activeRequests[$requestId];
            $duration = microtime(true) - $requestData['start_time'];
            $statusCode = $response->getStatusCode();
            $responseSize = strlen((string) $response->getBody());
            
            // Record response metrics
            Monitor::timing('http3.request.duration', $duration * 1000);
            Monitor::increment("http3.responses.status.{$statusCode}");
            Monitor::gauge('http3.response.size', $responseSize);
            
            // Performance classification
            if ($duration > 1.0) {
                Monitor::increment('http3.requests.slow');
            }
            
            if ($statusCode >= 500) {
                Monitor::increment('http3.requests.server_error');
            } elseif ($statusCode >= 400) {
                Monitor::increment('http3.requests.client_error');
            } else {
                Monitor::increment('http3.requests.success');
            }

            // Finish request span
            if (isset($requestData['span_id'])) {
                Trace::finishSpan($requestData['span_id'], [
                    'http.status_code' => $statusCode,
                    'http.response_content_length' => $responseSize,
                    'http3.request_duration_ms' => round($duration * 1000, 2),
                    'http3.stream_multiplexed' => $this->isStreamMultiplexed($requestData),
                    'http3.server_push_used' => $response->hasHeader('push-policy')
                ]);
            }

            // Clean up
            unset($this->activeRequests[$requestId]);

            $this->logger->debug('HTTP/3 request completed', [
                'request_id' => $requestId,
                'duration_ms' => round($duration * 1000, 2),
                'status_code' => $statusCode,
                'response_size' => $responseSize
            ]);
        });
    }

    /**
     * Instrument HTTP/3 stream management
     */
    private function instrumentStreamManagement(Http3Protocol $protocol): void
    {
        if (!$this->config['track_stream_multiplexing']) {
            return;
        }

        // Stream created
        $protocol->onStreamCreated(function($streamId, $connectionId) {
            Monitor::increment('http3.streams.created');
            Monitor::gauge('http3.streams.active', $this->getActiveStreamCount() + 1);
            
            $this->streamMetrics[$streamId] = [
                'id' => $streamId,
                'connection_id' => $connectionId,
                'created_at' => microtime(true),
                'bytes_sent' => 0,
                'bytes_received' => 0,
                'frames_sent' => 0,
                'frames_received' => 0
            ];

            $this->logger->debug('HTTP/3 stream created', [
                'stream_id' => $streamId,
                'connection_id' => $connectionId
            ]);
        });

        // Stream closed
        $protocol->onStreamClosed(function($streamId, $errorCode = null) {
            if (!isset($this->streamMetrics[$streamId])) {
                return;
            }

            $streamData = $this->streamMetrics[$streamId];
            $duration = microtime(true) - $streamData['created_at'];
            
            Monitor::increment('http3.streams.closed');
            Monitor::gauge('http3.streams.active', $this->getActiveStreamCount() - 1);
            Monitor::timing('http3.stream.duration', $duration * 1000);
            
            if ($errorCode) {
                Monitor::increment('http3.streams.error');
                Monitor::increment("http3.streams.error.{$errorCode}");
            }

            // Record stream summary
            Monitor::gauge('http3.stream.bytes_total', 
                $streamData['bytes_sent'] + $streamData['bytes_received']);
            Monitor::gauge('http3.stream.frames_total', 
                $streamData['frames_sent'] + $streamData['frames_received']);

            unset($this->streamMetrics[$streamId]);

            $this->logger->debug('HTTP/3 stream closed', [
                'stream_id' => $streamId,
                'duration_ms' => round($duration * 1000, 2),
                'error_code' => $errorCode
            ]);
        });

        // Stream data transfer
        $protocol->onStreamData(function($streamId, $data, $direction) {
            if (!isset($this->streamMetrics[$streamId])) {
                return;
            }

            $dataSize = strlen($data);
            if ($direction === 'sent') {
                $this->streamMetrics[$streamId]['bytes_sent'] += $dataSize;
                $this->streamMetrics[$streamId]['frames_sent']++;
                Monitor::gauge('http3.stream.bytes_sent', $dataSize);
            } else {
                $this->streamMetrics[$streamId]['bytes_received'] += $dataSize;
                $this->streamMetrics[$streamId]['frames_received']++;
                Monitor::gauge('http3.stream.bytes_received', $dataSize);
            }
        });
    }

    /**
     * Instrument HTTP/3 server push
     */
    private function instrumentServerPush(Http3Protocol $protocol): void
    {
        if (!$this->config['track_server_push']) {
            return;
        }

        // Push promise sent
        $protocol->onPushPromise(function($streamId, $promisedStreamId, $headers) {
            Monitor::increment('http3.push.promises_sent');
            
            if ($this->shouldSample('push')) {
                Trace::event('http3.push.promise_sent', [
                    'stream_id' => $streamId,
                    'promised_stream_id' => $promisedStreamId,
                    'push_url' => $headers[':path'] ?? 'unknown'
                ]);
            }

            $this->logger->debug('HTTP/3 push promise sent', [
                'stream_id' => $streamId,
                'promised_stream_id' => $promisedStreamId
            ]);
        });

        // Push resource sent
        $protocol->onPushResource(function($promisedStreamId, $resource, $size) {
            Monitor::increment('http3.push.resources_sent');
            Monitor::gauge('http3.push.resource_size', $size);
            
            $this->logger->debug('HTTP/3 push resource sent', [
                'promised_stream_id' => $promisedStreamId,
                'resource_size' => $size
            ]);
        });

        // Push cancelled
        $protocol->onPushCancelled(function($promisedStreamId, $reason) {
            Monitor::increment('http3.push.cancelled');
            Monitor::increment("http3.push.cancelled.{$reason}");
            
            $this->logger->debug('HTTP/3 push cancelled', [
                'promised_stream_id' => $promisedStreamId,
                'reason' => $reason
            ]);
        });
    }

    /**
     * Instrument QUIC connection management
     */
    private function instrumentQuicConnection(Http3Protocol $protocol): void
    {
        if (!$this->config['track_quic_metrics']) {
            return;
        }

        // Connection established
        $protocol->onConnectionEstablished(function($connectionId, $clientAddress) {
            Monitor::increment('http3.connections.established');
            Monitor::gauge('http3.connections.active', $this->getActiveConnectionCount() + 1);
            
            $this->connectionMetrics[$connectionId] = [
                'id' => $connectionId,
                'client_address' => $clientAddress,
                'established_at' => microtime(true),
                'streams_created' => 0,
                'bytes_sent' => 0,
                'bytes_received' => 0,
                'migrations' => 0
            ];

            if ($this->shouldSample('connection')) {
                $span = Trace::startSpan('http3.connection', [
                    'connection.id' => $connectionId,
                    'connection.client_address' => $clientAddress,
                    'protocol' => 'http3/quic'
                ]);
                
                $this->connectionMetrics[$connectionId]['span_id'] = $span;
            }

            $this->logger->debug('HTTP/3 QUIC connection established', [
                'connection_id' => $connectionId,
                'client_address' => $clientAddress
            ]);
        });

        // Connection migration
        if ($this->config['track_connection_migration']) {
            $protocol->onConnectionMigration(function($connectionId, $newAddress, $oldAddress) {
                if (isset($this->connectionMetrics[$connectionId])) {
                    $this->connectionMetrics[$connectionId]['migrations']++;
                }
                
                Monitor::increment('http3.connections.migrations');
                
                if (isset($this->connectionMetrics[$connectionId]['span_id'])) {
                    Trace::event('http3.connection.migration', [
                        'connection.id' => $connectionId,
                        'connection.old_address' => $oldAddress,
                        'connection.new_address' => $newAddress
                    ]);
                }

                $this->logger->info('HTTP/3 connection migration', [
                    'connection_id' => $connectionId,
                    'old_address' => $oldAddress,
                    'new_address' => $newAddress
                ]);
            });
        }

        // Connection closed
        $protocol->onConnectionClosed(function($connectionId, $errorCode, $reason) {
            if (!isset($this->connectionMetrics[$connectionId])) {
                return;
            }

            $connectionData = $this->connectionMetrics[$connectionId];
            $duration = microtime(true) - $connectionData['established_at'];
            
            Monitor::increment('http3.connections.closed');
            Monitor::gauge('http3.connections.active', $this->getActiveConnectionCount() - 1);
            Monitor::timing('http3.connection.duration', $duration * 1000);
            
            if ($errorCode) {
                Monitor::increment('http3.connections.error');
            }

            // Finish connection span
            if (isset($connectionData['span_id'])) {
                Trace::finishSpan($connectionData['span_id'], [
                    'connection.duration_ms' => round($duration * 1000, 2),
                    'connection.streams_created' => $connectionData['streams_created'],
                    'connection.bytes_total' => $connectionData['bytes_sent'] + $connectionData['bytes_received'],
                    'connection.migrations' => $connectionData['migrations'],
                    'connection.error_code' => $errorCode,
                    'connection.close_reason' => $reason
                ]);
            }

            unset($this->connectionMetrics[$connectionId]);

            $this->logger->debug('HTTP/3 QUIC connection closed', [
                'connection_id' => $connectionId,
                'duration_ms' => round($duration * 1000, 2),
                'error_code' => $errorCode,
                'reason' => $reason
            ]);
        });
    }

    /**
     * Instrument performance metrics
     */
    private function instrumentPerformanceMetrics(Http3Protocol $protocol): void
    {
        if (!$this->config['performance_optimization']) {
            return;
        }

        // Round-trip time measurements
        $protocol->onRttMeasurement(function($connectionId, $rtt, $rttVariation) {
            Monitor::gauge('http3.connection.rtt', $rtt * 1000); // Convert to ms
            Monitor::gauge('http3.connection.rtt_variation', $rttVariation * 1000);
            
            // Alert on high latency
            if ($rtt > 0.5) { // 500ms threshold
                Monitor::increment('http3.connections.high_latency');
            }
        });

        // Congestion control metrics
        $protocol->onCongestionUpdate(function($connectionId, $cwnd, $ssthresh, $inFlight) {
            Monitor::gauge('http3.congestion.window', $cwnd);
            Monitor::gauge('http3.congestion.ssthresh', $ssthresh);
            Monitor::gauge('http3.congestion.in_flight', $inFlight);
        });

        // Packet loss detection
        $protocol->onPacketLoss(function($connectionId, $packetsLost, $packetsTotal) {
            $lossRate = $packetsTotal > 0 ? ($packetsLost / $packetsTotal) * 100 : 0;
            
            Monitor::gauge('http3.packet.loss_rate', $lossRate);
            Monitor::increment('http3.packets.lost', $packetsLost);
            
            // Alert on high packet loss
            if ($lossRate > 5.0) { // 5% threshold
                Monitor::increment('http3.connections.high_packet_loss');
            }
        });
    }

    /**
     * Monitor request security
     */
    private function monitorRequestSecurity(ServerRequestInterface $request): void
    {
        $uri = (string) $request->getUri();
        $method = $request->getMethod();
        $userAgent = $request->getHeaderLine('user-agent');
        $clientIp = $this->extractClientIp($request);
        
        // Check for suspicious patterns
        $suspiciousPatterns = [
            'sql_injection' => $this->checkSqlInjection($uri, $request->getBody()->getContents()),
            'xss_attempt' => $this->checkXssAttempt($uri),
            'path_traversal' => $this->checkPathTraversal($uri),
            'suspicious_user_agent' => $this->checkSuspiciousUserAgent($userAgent),
            'rate_limit_exceeded' => $this->checkRateLimit($clientIp)
        ];

        foreach ($suspiciousPatterns as $pattern => $detected) {
            if ($detected) {
                Monitor::security("http3_{$pattern}", [
                    'client_ip' => $clientIp,
                    'uri' => $uri,
                    'method' => $method,
                    'user_agent' => $userAgent,
                    'severity' => $this->getSecuritySeverity($pattern)
                ]);
            }
        }
    }

    /**
     * Helper methods
     */
    private function shouldSample(string $eventType): bool
    {
        $rate = $this->config['sample_rate'];
        
        // Adjust sampling rate based on event type
        switch ($eventType) {
            case 'request':
                $rate = $this->config['sample_requests'] ? $rate : 0;
                break;
            case 'stream':
                $rate = $this->config['sample_streams'] ? $rate * 0.5 : 0;
                break;
            case 'connection':
                $rate = $rate * 0.1; // Lower sampling for connections
                break;
            case 'push':
                $rate = $rate * 2; // Higher sampling for server push
                break;
        }
        
        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function generateRequestId(): string
    {
        return uniqid('http3_req_', true);
    }

    private function extractClientIp(ServerRequestInterface $request): string
    {
        // Check various headers for client IP
        $headers = ['x-forwarded-for', 'x-real-ip', 'cf-connecting-ip'];
        
        foreach ($headers as $header) {
            $ip = $request->getHeaderLine($header);
            if (!empty($ip)) {
                return explode(',', $ip)[0];
            }
        }
        
        return $request->getAttribute('client_ip', 'unknown');
    }

    private function isStreamMultiplexed(array $requestData): bool
    {
        $connectionId = $requestData['connection_id'];
        $activeStreamsOnConnection = array_filter(
            $this->streamMetrics,
            fn($stream) => $stream['connection_id'] === $connectionId
        );
        
        return count($activeStreamsOnConnection) > 1;
    }

    private function getActiveStreamCount(): int
    {
        return count($this->streamMetrics);
    }

    private function getActiveConnectionCount(): int
    {
        return count($this->connectionMetrics);
    }

    // Security check methods (simplified implementations)
    private function checkSqlInjection(string $uri, string $body): bool
    {
        $patterns = ['/union\s+select/i', '/or\s+1\s*=\s*1/i', '/drop\s+table/i'];
        $content = $uri . ' ' . $body;
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }

    private function checkXssAttempt(string $uri): bool
    {
        $patterns = ['/<script/i', '/javascript:/i', '/on\w+\s*=/i'];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $uri)) {
                return true;
            }
        }
        
        return false;
    }

    private function checkPathTraversal(string $uri): bool
    {
        return str_contains($uri, '../') || str_contains($uri, '..\\');
    }

    private function checkSuspiciousUserAgent(string $userAgent): bool
    {
        $suspicious = ['bot', 'scanner', 'probe', 'test', 'curl', 'wget'];
        foreach ($suspicious as $pattern) {
            if (str_contains(strtolower($userAgent), $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function checkRateLimit(string $clientIp): bool
    {
        // Simplified rate limiting check
        return false;
    }

    private function getSecuritySeverity(string $pattern): string
    {
        $severityMap = [
            'sql_injection' => 'high',
            'xss_attempt' => 'medium',
            'path_traversal' => 'high',
            'suspicious_user_agent' => 'low',
            'rate_limit_exceeded' => 'medium'
        ];

        return $severityMap[$pattern] ?? 'medium';
    }
}