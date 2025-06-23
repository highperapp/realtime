<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\Media;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
use HighPerApp\HighPer\Realtime\Protocols\Http3\Http3Server;
use HighPerApp\HighPer\Realtime\Protocols\WebRTC\WebRTCDataChannelProtocol;
use HighPerApp\HighPer\Realtime\Protocols\WebTransport\WebTransportProtocol;
use Psr\Log\LoggerInterface;

/**
 * Live Video/Audio Streaming Protocol
 * 
 * Implements real-time media streaming using modern protocols:
 * - WebRTC for P2P streaming with ultra-low latency
 * - WebTransport for reliable media delivery over HTTP/3
 * - HTTP/3 streaming for scalable broadcast scenarios
 * - Adaptive bitrate streaming and quality optimization
 */
class MediaStreamProtocol implements ProtocolInterface
{
    private LoggerInterface $logger;
    private array $config;
    private Http3Server $http3Server;
    private WebRTCDataChannelProtocol $webrtcProtocol;
    private WebTransportProtocol $webtransportProtocol;
    private MediaEncoder $mediaEncoder;
    private AdaptiveBitrateManager $abrManager;
    private StreamMultiplexer $streamMultiplexer;
    private QualityController $qualityController;
    
    private array $streams = [];
    private array $subscribers = [];
    private array $broadcasters = [];
    private array $mediaRooms = [];
    private array $eventHandlers = [];
    private bool $isRunning = false;
    private array $metrics = [];

    // Media stream types
    private const STREAM_VIDEO = 'video';
    private const STREAM_AUDIO = 'audio';
    private const STREAM_SCREEN = 'screen';
    private const STREAM_DATA = 'data';

    // Quality levels
    private const QUALITY_LEVELS = [
        'ultra' => ['width' => 3840, 'height' => 2160, 'bitrate' => 15000000, 'fps' => 60],
        'high' => ['width' => 1920, 'height' => 1080, 'bitrate' => 5000000, 'fps' => 30],
        'medium' => ['width' => 1280, 'height' => 720, 'bitrate' => 2500000, 'fps' => 30],
        'low' => ['width' => 854, 'height' => 480, 'bitrate' => 1000000, 'fps' => 24],
        'mobile' => ['width' => 640, 'height' => 360, 'bitrate' => 500000, 'fps' => 15]
    ];

    public function __construct(
        LoggerInterface $logger,
        array $config,
        Http3Server $http3Server,
        WebRTCDataChannelProtocol $webrtcProtocol,
        WebTransportProtocol $webtransportProtocol
    ) {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->http3Server = $http3Server;
        $this->webrtcProtocol = $webrtcProtocol;
        $this->webtransportProtocol = $webtransportProtocol;
        
        $this->initializeComponents();
        $this->initializeMetrics();
    }

    /**
     * Initialize media streaming components
     */
    private function initializeComponents(): void
    {
        $this->mediaEncoder = new MediaEncoder($this->logger, $this->config);
        $this->abrManager = new AdaptiveBitrateManager($this->logger, $this->config);
        $this->streamMultiplexer = new StreamMultiplexer($this->logger, $this->config);
        $this->qualityController = new QualityController($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'active_streams' => 0,
            'total_streams' => 0,
            'subscribers' => 0,
            'broadcasters' => 0,
            'bytes_streamed' => 0,
            'frames_processed' => 0,
            'dropped_frames' => 0,
            'quality_adaptations' => 0,
            'p2p_streams' => 0,
            'server_relayed_streams' => 0,
            'average_latency' => 0,
            'bandwidth_utilization' => 0,
            'concurrent_rooms' => 0,
            'encoding_time' => 0,
            'streaming_efficiency' => 0
        ];
    }

    /**
     * Check if request supports media streaming
     */
    public function supports(Request $request): bool
    {
        $path = $request->getUri()->getPath();
        $userAgent = strtolower($request->getHeader('user-agent') ?? '');
        
        // Check for media streaming endpoints
        $isMediaRequest = strpos($path, '/stream') !== false ||
                         strpos($path, '/media') !== false ||
                         strpos($path, '/broadcast') !== false ||
                         $request->hasHeader('x-media-stream');

        // Check browser media capabilities
        $supportsMedia = strpos($userAgent, 'chrome') !== false ||
                        strpos($userAgent, 'firefox') !== false ||
                        strpos($userAgent, 'safari') !== false ||
                        strpos($userAgent, 'edge') !== false;

        return $isMediaRequest && $supportsMedia;
    }

    /**
     * Handle media streaming handshake
     */
    public function handleHandshake(Request $request): \Generator
    {
        try {
            $path = $request->getUri()->getPath();
            
            // Route to appropriate handler
            if (strpos($path, '/stream/broadcast') !== false) {
                return yield $this->handleBroadcastHandshake($request);
            } elseif (strpos($path, '/stream/subscribe') !== false) {
                return yield $this->handleSubscriberHandshake($request);
            } elseif (strpos($path, '/stream/p2p') !== false) {
                return yield $this->handleP2PStreamHandshake($request);
            } else {
                return yield $this->handleGenericStreamHandshake($request);
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Media streaming handshake failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            return new Response(500, [], 'Media streaming handshake failed');
        }
    }

    /**
     * Handle broadcaster handshake
     */
    private function handleBroadcastHandshake(Request $request): \Generator
    {
        // Extract stream parameters
        $streamId = $this->extractStreamId($request);
        $roomId = $this->extractRoomId($request);
        $mediaTypes = $this->extractMediaTypes($request);
        $quality = $this->extractQuality($request);
        
        // Determine optimal transport protocol
        $transport = yield $this->selectOptimalTransport($request, 'broadcast');
        
        // Create broadcaster session
        $broadcaster = new MediaBroadcaster(
            $this->generateBroadcasterId(),
            $streamId,
            $roomId,
            $transport,
            $mediaTypes,
            $this->logger,
            $this->config
        );

        // Configure encoding
        yield $this->configureEncoding($broadcaster, $quality);

        // Store broadcaster
        $broadcasterId = $broadcaster->getId();
        $this->broadcasters[$broadcasterId] = $broadcaster;
        $this->metrics['broadcasters']++;
        $this->metrics['total_streams']++;
        $this->metrics['active_streams']++;

        // Setup media room
        if (!isset($this->mediaRooms[$roomId])) {
            $this->mediaRooms[$roomId] = [
                'broadcasters' => [],
                'subscribers' => [],
                'streams' => [],
                'created_at' => time()
            ];
            $this->metrics['concurrent_rooms']++;
        }
        
        $this->mediaRooms[$roomId]['broadcasters'][$broadcasterId] = $broadcaster;

        // Setup broadcaster handlers
        yield $this->setupBroadcasterHandlers($broadcaster);

        // Send handshake response
        $response = new Response(200, [
            'content-type' => 'application/json',
            'x-transport' => $transport,
            'x-media-streaming' => 'broadcast'
        ], json_encode([
            'type' => 'broadcast_ready',
            'broadcaster_id' => $broadcasterId,
            'stream_id' => $streamId,
            'room_id' => $roomId,
            'transport' => $transport,
            'media_types' => $mediaTypes,
            'quality_levels' => self::QUALITY_LEVELS,
            'encoding_config' => $broadcaster->getEncodingConfig(),
            'rtmp_endpoint' => $this->config['rtmp_endpoint'] ?? null,
            'webrtc_config' => $transport === 'webrtc' ? $this->getWebRTCConfig() : null
        ]));

        $this->logger->info('Media broadcast session established', [
            'broadcaster_id' => $broadcasterId,
            'stream_id' => $streamId,
            'room_id' => $roomId,
            'transport' => $transport,
            'media_types' => $mediaTypes
        ]);

        // Trigger connect event
        yield $this->triggerEvent('broadcast_started', $broadcaster);

        return $response;
    }

    /**
     * Handle subscriber handshake
     */
    private function handleSubscriberHandshake(Request $request): \Generator
    {
        // Extract subscription parameters
        $streamId = $this->extractStreamId($request);
        $roomId = $this->extractRoomId($request);
        $quality = $this->extractQuality($request);
        $mediaTypes = $this->extractMediaTypes($request);
        
        // Check if room exists
        if (!isset($this->mediaRooms[$roomId])) {
            return new Response(404, [], 'Media room not found');
        }

        // Determine optimal transport for subscriber
        $transport = yield $this->selectOptimalTransport($request, 'subscribe');
        
        // Create subscriber session
        $subscriber = new MediaSubscriber(
            $this->generateSubscriberId(),
            $streamId,
            $roomId,
            $transport,
            $mediaTypes,
            $this->logger,
            $this->config
        );

        // Configure quality preferences
        yield $this->configureSubscriberQuality($subscriber, $quality);

        // Store subscriber
        $subscriberId = $subscriber->getId();
        $this->subscribers[$subscriberId] = $subscriber;
        $this->metrics['subscribers']++;

        // Add to room
        $this->mediaRooms[$roomId]['subscribers'][$subscriberId] = $subscriber;

        // Setup subscriber handlers
        yield $this->setupSubscriberHandlers($subscriber);

        // Get available streams in room
        $availableStreams = $this->getAvailableStreams($roomId);

        // Send handshake response
        $response = new Response(200, [
            'content-type' => 'application/json',
            'x-transport' => $transport,
            'x-media-streaming' => 'subscribe'
        ], json_encode([
            'type' => 'subscribe_ready',
            'subscriber_id' => $subscriberId,
            'stream_id' => $streamId,
            'room_id' => $roomId,
            'transport' => $transport,
            'available_streams' => $availableStreams,
            'quality_config' => $subscriber->getQualityConfig(),
            'adaptive_streaming' => $this->config['enable_adaptive_streaming']
        ]));

        $this->logger->info('Media subscriber session established', [
            'subscriber_id' => $subscriberId,
            'stream_id' => $streamId,
            'room_id' => $roomId,
            'transport' => $transport,
            'available_streams' => count($availableStreams)
        ]);

        // Auto-subscribe to available streams
        foreach ($availableStreams as $stream) {
            yield $this->subscribeToStream($subscriber, $stream['stream_id']);
        }

        // Trigger connect event
        yield $this->triggerEvent('subscriber_joined', $subscriber);

        return $response;
    }

    /**
     * Handle P2P streaming handshake
     */
    private function handleP2PStreamHandshake(Request $request): \Generator
    {
        // P2P streaming uses WebRTC for direct peer-to-peer media
        $roomId = $this->extractRoomId($request);
        $mediaTypes = $this->extractMediaTypes($request);
        
        // Create P2P session via WebRTC protocol
        $webrtcResponse = yield $this->webrtcProtocol->handleHandshake($request);
        
        if ($webrtcResponse->getStatus() !== 200) {
            return $webrtcResponse;
        }

        // Extract peer ID from WebRTC response
        $responseBody = json_decode($webrtcResponse->getBody()->buffer(), true);
        $peerId = $responseBody['peer_id'];

        // Create P2P media session
        $p2pSession = new P2PMediaSession(
            $peerId,
            $roomId,
            $mediaTypes,
            $this->logger,
            $this->config
        );

        // Setup P2P streaming
        yield $this->setupP2PStreaming($p2pSession);

        $this->metrics['p2p_streams']++;

        $this->logger->info('P2P media streaming session established', [
            'peer_id' => $peerId,
            'room_id' => $roomId,
            'media_types' => $mediaTypes
        ]);

        // Enhance WebRTC response with media streaming info
        $enhancedResponse = json_decode($webrtcResponse->getBody()->buffer(), true);
        $enhancedResponse['media_streaming'] = [
            'type' => 'p2p',
            'media_types' => $mediaTypes,
            'quality_levels' => self::QUALITY_LEVELS,
            'adaptive_streaming' => true
        ];

        return new Response(200, [
            'content-type' => 'application/json',
            'x-transport' => 'webrtc',
            'x-media-streaming' => 'p2p'
        ], json_encode($enhancedResponse));
    }

    /**
     * Setup broadcaster handlers
     */
    private function setupBroadcasterHandlers(MediaBroadcaster $broadcaster): \Generator
    {
        // Handle incoming media data
        $broadcaster->onMediaData(function($mediaData) use ($broadcaster) {
            yield $this->handleIncomingMedia($broadcaster, $mediaData);
        });

        // Handle quality change requests
        $broadcaster->onQualityChange(function($quality) use ($broadcaster) {
            yield $this->handleQualityChange($broadcaster, $quality);
        });

        // Handle stream end
        $broadcaster->onStreamEnd(function() use ($broadcaster) {
            yield $this->handleStreamEnd($broadcaster);
        });

        // Handle connection errors
        $broadcaster->onError(function($error) use ($broadcaster) {
            yield $this->handleBroadcasterError($broadcaster, $error);
        });

        // Start broadcaster
        yield $broadcaster->start();
    }

    /**
     * Setup subscriber handlers
     */
    private function setupSubscriberHandlers(MediaSubscriber $subscriber): \Generator
    {
        // Handle quality adaptation
        $subscriber->onQualityAdaptation(function($newQuality) use ($subscriber) {
            yield $this->handleSubscriberQualityAdaptation($subscriber, $newQuality);
        });

        // Handle buffering events
        $subscriber->onBuffering(function($isBuffering) use ($subscriber) {
            yield $this->handleBuffering($subscriber, $isBuffering);
        });

        // Handle disconnect
        $subscriber->onDisconnect(function($reason) use ($subscriber) {
            yield $this->handleSubscriberDisconnect($subscriber, $reason);
        });

        // Start subscriber
        yield $subscriber->start();
    }

    /**
     * Handle incoming media data from broadcaster
     */
    private function handleIncomingMedia(MediaBroadcaster $broadcaster, array $mediaData): \Generator
    {
        $startTime = microtime(true);
        
        try {
            $roomId = $broadcaster->getRoomId();
            $streamId = $broadcaster->getStreamId();

            // Process and encode media
            $encodedMedia = yield $this->mediaEncoder->encode($mediaData, $broadcaster->getEncodingConfig());

            // Apply adaptive bitrate if enabled
            if ($this->config['enable_adaptive_streaming']) {
                $encodedMedia = yield $this->abrManager->processMedia($encodedMedia, $broadcaster);
            }

            // Store stream data
            if (!isset($this->streams[$streamId])) {
                $this->streams[$streamId] = [
                    'broadcaster_id' => $broadcaster->getId(),
                    'room_id' => $roomId,
                    'created_at' => time(),
                    'last_frame' => 0
                ];
            }
            
            $this->streams[$streamId]['last_frame'] = time();

            // Distribute to subscribers
            yield $this->distributeMediaToSubscribers($roomId, $streamId, $encodedMedia);

            // Update metrics
            $processingTime = microtime(true) - $startTime;
            $this->metrics['frames_processed']++;
            $this->metrics['bytes_streamed'] += $encodedMedia['size'] ?? 0;
            $this->metrics['encoding_time'] = ($this->metrics['encoding_time'] + $processingTime) / 2;

            $this->logger->debug('Media frame processed and distributed', [
                'broadcaster_id' => $broadcaster->getId(),
                'stream_id' => $streamId,
                'frame_size' => $encodedMedia['size'] ?? 0,
                'processing_time_ms' => round($processingTime * 1000, 2),
                'subscribers_count' => count($this->mediaRooms[$roomId]['subscribers'] ?? [])
            ]);

        } catch (\Throwable $e) {
            $this->metrics['dropped_frames']++;
            
            $this->logger->error('Error processing incoming media', [
                'broadcaster_id' => $broadcaster->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Distribute media to subscribers
     */
    private function distributeMediaToSubscribers(string $roomId, string $streamId, array $encodedMedia): \Generator
    {
        if (!isset($this->mediaRooms[$roomId]['subscribers'])) {
            return;
        }

        $subscribers = $this->mediaRooms[$roomId]['subscribers'];
        $futures = [];

        foreach ($subscribers as $subscriber) {
            if ($subscriber->isSubscribedTo($streamId)) {
                $futures[] = $this->sendMediaToSubscriber($subscriber, $encodedMedia);
            }
        }

        if (!empty($futures)) {
            yield \Amp\Future::awaitAll($futures);
        }
    }

    /**
     * Send media to individual subscriber
     */
    private function sendMediaToSubscriber(MediaSubscriber $subscriber, array $encodedMedia): \Generator
    {
        try {
            // Apply subscriber-specific quality adaptation
            $adaptedMedia = yield $this->qualityController->adaptForSubscriber($subscriber, $encodedMedia);
            
            // Send via appropriate transport
            yield $subscriber->sendMedia($adaptedMedia);

        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send media to subscriber', [
                'subscriber_id' => $subscriber->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send message (media control)
     */
    public function sendMessage(string $connectionId, array $data): \Generator
    {
        $target = $this->findConnection($connectionId);
        
        if (!$target) {
            throw new \InvalidArgumentException("Connection {$connectionId} not found");
        }

        if ($target instanceof MediaBroadcaster) {
            yield $target->sendControlMessage($data);
        } elseif ($target instanceof MediaSubscriber) {
            yield $target->sendControlMessage($data);
        }
    }

    /**
     * Broadcast control message
     */
    public function broadcast(array $connectionIds, array $data): \Generator
    {
        $futures = [];
        
        foreach ($connectionIds as $connectionId) {
            try {
                $futures[] = $this->sendMessage($connectionId, $data);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send to connection', [
                    'connection_id' => $connectionId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if (!empty($futures)) {
            yield \Amp\Future::awaitAll($futures);
        }
    }

    /**
     * Broadcast to room
     */
    public function broadcastToChannel(string $roomId, array $data): \Generator
    {
        if (!isset($this->mediaRooms[$roomId])) {
            return;
        }

        $connectionIds = [];
        
        // Add broadcasters
        foreach ($this->mediaRooms[$roomId]['broadcasters'] as $broadcaster) {
            $connectionIds[] = $broadcaster->getId();
        }
        
        // Add subscribers
        foreach ($this->mediaRooms[$roomId]['subscribers'] as $subscriber) {
            $connectionIds[] = $subscriber->getId();
        }

        yield $this->broadcast($connectionIds, $data);
    }

    /**
     * Join room
     */
    public function joinChannel(string $connectionId, string $roomId): \Generator
    {
        $connection = $this->findConnection($connectionId);
        
        if (!$connection) {
            throw new \InvalidArgumentException("Connection {$connectionId} not found");
        }

        if (!isset($this->mediaRooms[$roomId])) {
            $this->mediaRooms[$roomId] = [
                'broadcasters' => [],
                'subscribers' => [],
                'streams' => [],
                'created_at' => time()
            ];
            $this->metrics['concurrent_rooms']++;
        }

        if ($connection instanceof MediaBroadcaster) {
            $this->mediaRooms[$roomId]['broadcasters'][$connectionId] = $connection;
        } elseif ($connection instanceof MediaSubscriber) {
            $this->mediaRooms[$roomId]['subscribers'][$connectionId] = $connection;
        }

        $connection->setRoomId($roomId);
    }

    /**
     * Leave room
     */
    public function leaveChannel(string $connectionId, string $roomId): \Generator
    {
        if (!isset($this->mediaRooms[$roomId])) {
            return;
        }

        unset($this->mediaRooms[$roomId]['broadcasters'][$connectionId]);
        unset($this->mediaRooms[$roomId]['subscribers'][$connectionId]);

        // Clean up empty rooms
        if (empty($this->mediaRooms[$roomId]['broadcasters']) && 
            empty($this->mediaRooms[$roomId]['subscribers'])) {
            unset($this->mediaRooms[$roomId]);
            $this->metrics['concurrent_rooms']--;
        }
    }

    /**
     * Close connection
     */
    public function closeConnection(string $connectionId, int $code = 1000, string $reason = ''): \Generator
    {
        $connection = $this->findConnection($connectionId);
        
        if (!$connection) {
            return;
        }

        $roomId = $connection->getRoomId();
        
        if ($roomId) {
            yield $this->leaveChannel($connectionId, $roomId);
        }

        yield $connection->close($reason);

        // Remove from collections
        unset($this->broadcasters[$connectionId]);
        unset($this->subscribers[$connectionId]);

        // Update metrics
        if ($connection instanceof MediaBroadcaster) {
            $this->metrics['broadcasters']--;
            $this->metrics['active_streams']--;
        } elseif ($connection instanceof MediaSubscriber) {
            $this->metrics['subscribers']--;
        }
    }

    /**
     * Start media streaming server
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting Media Streaming server');

        // Start underlying protocols
        if (!$this->http3Server->isRunning()) {
            yield $this->http3Server->start();
        }
        
        if (!$this->webrtcProtocol->isRunning()) {
            yield $this->webrtcProtocol->start();
        }
        
        if (!$this->webtransportProtocol->isRunning()) {
            yield $this->webtransportProtocol->start();
        }

        // Start media encoder
        yield $this->mediaEncoder->start();

        $this->isRunning = true;
        
        // Start monitoring
        \Amp\async(function() {
            yield $this->startMonitoring();
        });

        $this->logger->info('Media Streaming server started');
    }

    /**
     * Stop server
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping Media Streaming server');

        // Close all connections
        $closeFutures = [];
        
        foreach ($this->broadcasters as $broadcaster) {
            $closeFutures[] = $this->closeConnection($broadcaster->getId());
        }
        
        foreach ($this->subscribers as $subscriber) {
            $closeFutures[] = $this->closeConnection($subscriber->getId());
        }

        if (!empty($closeFutures)) {
            yield \Amp\Future::awaitAll($closeFutures);
        }

        $this->isRunning = false;
        
        $this->logger->info('Media Streaming server stopped');
    }

    /**
     * Get protocol name
     */
    public function getName(): string
    {
        return 'MediaStreaming';
    }

    /**
     * Get protocol version
     */
    public function getVersion(): string
    {
        return 'WebRTC-HTTP3-Streaming-1.0';
    }

    /**
     * Get connection count
     */
    public function getConnectionCount(): int
    {
        return count($this->broadcasters) + count($this->subscribers);
    }

    /**
     * Get connections in channel (room)
     */
    public function getChannelConnections(string $roomId): array
    {
        if (!isset($this->mediaRooms[$roomId])) {
            return [];
        }

        $connections = [];
        
        foreach ($this->mediaRooms[$roomId]['broadcasters'] as $broadcaster) {
            $connections[] = $broadcaster->getId();
        }
        
        foreach ($this->mediaRooms[$roomId]['subscribers'] as $subscriber) {
            $connections[] = $subscriber->getId();
        }

        return $connections;
    }

    /**
     * Get metrics
     */
    public function getMetrics(): array
    {
        // Update dynamic metrics
        $this->metrics['active_streams'] = count($this->streams);
        $this->metrics['broadcasters'] = count($this->broadcasters);
        $this->metrics['subscribers'] = count($this->subscribers);
        $this->metrics['concurrent_rooms'] = count($this->mediaRooms);

        // Calculate streaming efficiency
        $totalFrames = $this->metrics['frames_processed'] + $this->metrics['dropped_frames'];
        if ($totalFrames > 0) {
            $this->metrics['streaming_efficiency'] = $this->metrics['frames_processed'] / $totalFrames;
        }

        return $this->metrics;
    }

    /**
     * Register event handlers
     */
    public function onConnect(callable $handler): void
    {
        $this->eventHandlers['connect'][] = $handler;
    }

    public function onDisconnect(callable $handler): void
    {
        $this->eventHandlers['disconnect'][] = $handler;
    }

    public function onMessage(callable $handler): void
    {
        $this->eventHandlers['message'][] = $handler;
    }

    public function onError(callable $handler): void
    {
        $this->eventHandlers['error'][] = $handler;
    }

    /**
     * Media streaming specific event handlers
     */
    public function onBroadcastStarted(callable $handler): void
    {
        $this->eventHandlers['broadcast_started'][] = $handler;
    }

    public function onStreamEnded(callable $handler): void
    {
        $this->eventHandlers['stream_ended'][] = $handler;
    }

    public function onQualityChanged(callable $handler): void
    {
        $this->eventHandlers['quality_changed'][] = $handler;
    }

    /**
     * Helper methods
     */
    private function extractStreamId(Request $request): string
    {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        return $params['stream'] ?? 'default-' . bin2hex(random_bytes(4));
    }

    private function extractRoomId(Request $request): string
    {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        return $params['room'] ?? 'default';
    }

    private function extractMediaTypes(Request $request): array
    {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        
        if (isset($params['media'])) {
            return explode(',', $params['media']);
        }
        
        return ['video', 'audio']; // Default
    }

    private function extractQuality(Request $request): string
    {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        return $params['quality'] ?? 'medium';
    }

    private function generateBroadcasterId(): string
    {
        return 'bc-' . bin2hex(random_bytes(8));
    }

    private function generateSubscriberId(): string
    {
        return 'sub-' . bin2hex(random_bytes(8));
    }

    private function findConnection(string $connectionId)
    {
        return $this->broadcasters[$connectionId] ?? $this->subscribers[$connectionId] ?? null;
    }

    private function selectOptimalTransport(Request $request, string $mode): \Generator
    {
        // Simple protocol selection - could be enhanced with adaptive selection
        $userAgent = strtolower($request->getHeader('user-agent') ?? '');
        
        if ($mode === 'broadcast') {
            // Broadcasters typically prefer WebTransport or HTTP/3 for reliability
            if (strpos($userAgent, 'chrome') !== false) {
                return 'webtransport';
            }
            return 'http3';
        } else {
            // Subscribers can use WebRTC for P2P or HTTP/3 for server streaming
            if ($this->config['prefer_p2p'] && strpos($userAgent, 'chrome') !== false) {
                return 'webrtc';
            }
            return 'http3';
        }
    }

    private function triggerEvent(string $event, ...$args): \Generator
    {
        if (!isset($this->eventHandlers[$event])) {
            return;
        }

        foreach ($this->eventHandlers[$event] as $handler) {
            try {
                yield $handler(...$args);
            } catch (\Throwable $e) {
                $this->logger->error('Error in event handler', [
                    'event' => $event,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function startMonitoring(): \Generator
    {
        while ($this->isRunning) {
            yield \Amp\delay(30000); // 30 seconds
            
            try {
                // Monitor stream health
                yield $this->monitorStreamHealth();
                
                // Cleanup inactive streams
                yield $this->cleanupInactiveStreams();
                
                // Update bandwidth metrics
                yield $this->updateBandwidthMetrics();
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in media streaming monitoring', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Additional helper methods would be implemented here:
     * - configureEncoding()
     * - configureSubscriberQuality()
     * - setupP2PStreaming()
     * - getAvailableStreams()
     * - subscribeToStream()
     * - handleQualityChange()
     * - handleStreamEnd()
     * - monitorStreamHealth()
     * - cleanupInactiveStreams()
     * - updateBandwidthMetrics()
     * etc.
     */

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enable_adaptive_streaming' => true,
            'prefer_p2p' => true,
            'max_bitrate' => 10000000, // 10 Mbps
            'min_bitrate' => 500000,   // 500 Kbps
            'default_quality' => 'medium',
            'enable_hardware_encoding' => true,
            'rtmp_endpoint' => null,
            'max_streams_per_room' => 10,
            'stream_timeout' => 300, // 5 minutes
            'quality_adaptation_interval' => 5, // seconds
            'buffer_size' => 1024 * 1024, // 1MB
            'enable_recording' => false
        ];
    }

    // Placeholder methods for full implementation
    private function configureEncoding(MediaBroadcaster $broadcaster, string $quality): \Generator { return yield; }
    private function configureSubscriberQuality(MediaSubscriber $subscriber, string $quality): \Generator { return yield; }
    private function setupP2PStreaming($session): \Generator { return yield; }
    private function getAvailableStreams(string $roomId): array { return []; }
    private function subscribeToStream(MediaSubscriber $subscriber, string $streamId): \Generator { return yield; }
    private function getWebRTCConfig(): array { return []; }
    private function handleQualityChange(MediaBroadcaster $broadcaster, string $quality): \Generator { return yield; }
    private function handleStreamEnd(MediaBroadcaster $broadcaster): \Generator { return yield; }
    private function handleBroadcasterError(MediaBroadcaster $broadcaster, \Throwable $error): \Generator { return yield; }
    private function handleSubscriberQualityAdaptation(MediaSubscriber $subscriber, string $quality): \Generator { return yield; }
    private function handleBuffering(MediaSubscriber $subscriber, bool $isBuffering): \Generator { return yield; }
    private function handleSubscriberDisconnect(MediaSubscriber $subscriber, string $reason): \Generator { return yield; }
    private function handleGenericStreamHandshake(Request $request): \Generator { return yield new Response(200, [], '{}'); }
    private function monitorStreamHealth(): \Generator { return yield; }
    private function cleanupInactiveStreams(): \Generator { return yield; }
    private function updateBandwidthMetrics(): \Generator { return yield; }
}