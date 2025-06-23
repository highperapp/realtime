<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\WebRTC;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
use Psr\Log\LoggerInterface;

/**
 * WebRTC Data Channels Protocol Implementation
 * 
 * Implements peer-to-peer communication using WebRTC data channels
 * with server-assisted signaling and hybrid P2P architectures
 */
class WebRTCDataChannelProtocol implements ProtocolInterface
{
    private LoggerInterface $logger;
    private array $config;
    private WebRTCSignalingServer $signalingServer;
    private STUNTURNServer $stunTurnServer;
    private PeerConnectionManager $peerManager;
    private DataChannelManager $channelManager;
    
    private array $peers = [];
    private array $channels = [];
    private array $rooms = [];
    private array $eventHandlers = [];
    private bool $isRunning = false;
    private array $metrics = [];

    public function __construct(LoggerInterface $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->initializeComponents();
        $this->initializeMetrics();
    }

    /**
     * Initialize WebRTC components
     */
    private function initializeComponents(): void
    {
        $this->signalingServer = new WebRTCSignalingServer($this->logger, $this->config);
        $this->stunTurnServer = new STUNTURNServer($this->logger, $this->config);
        $this->peerManager = new PeerConnectionManager($this->logger, $this->config);
        $this->channelManager = new DataChannelManager($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'peer_connections' => 0,
            'active_peers' => 0,
            'data_channels' => 0,
            'active_channels' => 0,
            'messages_sent' => 0,
            'messages_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'p2p_connections' => 0,
            'server_relayed' => 0,
            'connection_failures' => 0,
            'nat_traversal_success' => 0,
            'bandwidth_saved' => 0,
            'rooms' => 0,
            'signaling_messages' => 0
        ];
    }

    /**
     * Check if request supports WebRTC
     */
    public function supports(Request $request): bool
    {
        $userAgent = strtolower($request->getHeader('user-agent') ?? '');
        $path = $request->getUri()->getPath();
        
        // Check for WebRTC-capable browsers
        $supportsWebRTC = strpos($userAgent, 'chrome') !== false ||
                         strpos($userAgent, 'firefox') !== false ||
                         strpos($userAgent, 'safari') !== false ||
                         strpos($userAgent, 'edge') !== false;

        // Check if it's a WebRTC signaling request
        $isWebRTCRequest = strpos($path, '/webrtc') !== false ||
                          $request->hasHeader('x-webrtc-signaling') ||
                          $request->hasHeader('sec-websocket-protocol') && 
                          strpos($request->getHeader('sec-websocket-protocol'), 'webrtc') !== false;

        return $supportsWebRTC && $isWebRTCRequest;
    }

    /**
     * Handle WebRTC signaling handshake
     */
    public function handleHandshake(Request $request): \Generator
    {
        try {
            $path = $request->getUri()->getPath();
            
            // Route to appropriate handler
            if (strpos($path, '/webrtc/signaling') !== false) {
                return yield $this->handleSignalingHandshake($request);
            } elseif (strpos($path, '/webrtc/stun') !== false) {
                return yield $this->handleSTUNRequest($request);
            } elseif (strpos($path, '/webrtc/turn') !== false) {
                return yield $this->handleTURNRequest($request);
            } else {
                return yield $this->handleDataChannelHandshake($request);
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('WebRTC handshake failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            return new Response(500, [], 'WebRTC handshake failed');
        }
    }

    /**
     * Handle signaling server handshake
     */
    private function handleSignalingHandshake(Request $request): \Generator
    {
        // Extract room ID from request
        $roomId = $this->extractRoomId($request);
        $peerId = $this->generatePeerId();
        
        // Create peer connection
        $peer = new WebRTCPeer(
            $peerId,
            $roomId,
            $request->getClient()->getRemoteAddress(),
            $this->logger,
            $this->config
        );

        // Store peer
        $this->peers[$peerId] = $peer;
        $this->metrics['peer_connections']++;
        $this->metrics['active_peers']++;

        // Add to room
        if (!isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = [];
            $this->metrics['rooms']++;
        }
        $this->rooms[$roomId][$peerId] = $peer;

        // Setup peer handlers
        yield $this->setupPeerHandlers($peer);

        // Generate ICE servers configuration
        $iceServers = $this->generateICEServers();

        // Send signaling response
        $response = new Response(200, [
            'content-type' => 'application/json',
            'access-control-allow-origin' => '*'
        ], json_encode([
            'type' => 'signaling_ready',
            'peer_id' => $peerId,
            'room_id' => $roomId,
            'ice_servers' => $iceServers,
            'data_channel_config' => $this->getDataChannelConfig(),
            'peers_in_room' => array_keys($this->rooms[$roomId])
        ]));

        $this->logger->info('WebRTC signaling handshake completed', [
            'peer_id' => $peerId,
            'room_id' => $roomId,
            'peers_in_room' => count($this->rooms[$roomId])
        ]);

        // Trigger connect event
        yield $this->triggerEvent('connect', $peer);

        return $response;
    }

    /**
     * Handle data channel establishment
     */
    private function handleDataChannelHandshake(Request $request): \Generator
    {
        $peerId = $request->getHeader('x-peer-id');
        $channelName = $request->getHeader('x-channel-name') ?? 'default';
        
        if (!$peerId || !isset($this->peers[$peerId])) {
            return new Response(400, [], 'Invalid peer ID');
        }

        $peer = $this->peers[$peerId];
        
        // Create data channel
        $channel = new WebRTCDataChannel(
            $this->generateChannelId(),
            $channelName,
            $peer,
            $this->logger,
            $this->config
        );

        // Store channel
        $channelId = $channel->getId();
        $this->channels[$channelId] = $channel;
        $this->metrics['data_channels']++;
        $this->metrics['active_channels']++;

        // Add channel to peer
        $peer->addDataChannel($channel);

        // Setup channel handlers
        yield $this->setupChannelHandlers($channel);

        $response = new Response(200, [
            'content-type' => 'application/json'
        ], json_encode([
            'type' => 'data_channel_ready',
            'channel_id' => $channelId,
            'channel_name' => $channelName,
            'peer_id' => $peerId,
            'config' => $channel->getConfig()
        ]));

        $this->logger->info('WebRTC data channel established', [
            'peer_id' => $peerId,
            'channel_id' => $channelId,
            'channel_name' => $channelName
        ]);

        return $response;
    }

    /**
     * Setup peer event handlers
     */
    private function setupPeerHandlers(WebRTCPeer $peer): \Generator
    {
        // Handle ICE candidates
        $peer->onICECandidate(function($candidate) use ($peer) {
            yield $this->relayICECandidate($peer, $candidate);
        });

        // Handle SDP offers/answers
        $peer->onSDP(function($sdp) use ($peer) {
            yield $this->relaySDP($peer, $sdp);
        });

        // Handle connection state changes
        $peer->onConnectionStateChange(function($state) use ($peer) {
            yield $this->handleConnectionStateChange($peer, $state);
        });

        // Handle peer disconnect
        $peer->onDisconnect(function($reason) use ($peer) {
            yield $this->handlePeerDisconnect($peer, $reason);
        });
    }

    /**
     * Setup data channel handlers
     */
    private function setupChannelHandlers(WebRTCDataChannel $channel): \Generator
    {
        // Handle incoming data
        $channel->onMessage(function($data) use ($channel) {
            yield $this->handleChannelMessage($channel, $data);
        });

        // Handle channel state changes
        $channel->onStateChange(function($state) use ($channel) {
            yield $this->handleChannelStateChange($channel, $state);
        });

        // Handle channel errors
        $channel->onError(function($error) use ($channel) {
            yield $this->handleChannelError($channel, $error);
        });
    }

    /**
     * Handle channel message
     */
    private function handleChannelMessage(WebRTCDataChannel $channel, $data): \Generator
    {
        $this->metrics['messages_received']++;
        $this->metrics['bytes_received'] += is_string($data) ? strlen($data) : 0;
        
        // Determine if this was P2P or server-relayed
        if ($channel->isPeerToPeer()) {
            $this->metrics['p2p_connections']++;
            $this->metrics['bandwidth_saved'] += is_string($data) ? strlen($data) : 0;
        } else {
            $this->metrics['server_relayed']++;
        }

        // Trigger message event
        yield $this->triggerEvent('message', $channel, $data);
    }

    /**
     * Send message through data channel
     */
    public function sendMessage(string $connectionId, array $data): \Generator
    {
        // connectionId can be either peer ID or channel ID
        $target = $this->findTarget($connectionId);
        
        if (!$target) {
            throw new \InvalidArgumentException("Connection {$connectionId} not found");
        }

        $encodedData = json_encode($data);
        
        if ($target instanceof WebRTCDataChannel) {
            // Send through specific channel
            yield $target->send($encodedData);
        } elseif ($target instanceof WebRTCPeer) {
            // Send through peer's default channel
            $defaultChannel = $target->getDefaultChannel();
            if ($defaultChannel) {
                yield $defaultChannel->send($encodedData);
            } else {
                throw new \RuntimeException("No default channel for peer {$connectionId}");
            }
        }

        $this->metrics['messages_sent']++;
        $this->metrics['bytes_sent'] += strlen($encodedData);
    }

    /**
     * Broadcast to multiple connections
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
     * Broadcast to room (all peers in room)
     */
    public function broadcastToChannel(string $roomId, array $data): \Generator
    {
        if (!isset($this->rooms[$roomId])) {
            return;
        }

        $peerIds = array_keys($this->rooms[$roomId]);
        yield $this->broadcast($peerIds, $data);
    }

    /**
     * Join peer to room
     */
    public function joinChannel(string $peerId, string $roomId): \Generator
    {
        if (!isset($this->peers[$peerId])) {
            throw new \InvalidArgumentException("Peer {$peerId} not found");
        }

        $peer = $this->peers[$peerId];
        
        // Remove from old room
        $oldRoomId = $peer->getRoomId();
        if ($oldRoomId && isset($this->rooms[$oldRoomId][$peerId])) {
            unset($this->rooms[$oldRoomId][$peerId]);
            if (empty($this->rooms[$oldRoomId])) {
                unset($this->rooms[$oldRoomId]);
                $this->metrics['rooms']--;
            }
        }

        // Add to new room
        if (!isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = [];
            $this->metrics['rooms']++;
        }
        
        $this->rooms[$roomId][$peerId] = $peer;
        $peer->setRoomId($roomId);

        // Notify other peers in room
        $roomPeers = array_keys($this->rooms[$roomId]);
        foreach ($roomPeers as $otherPeerId) {
            if ($otherPeerId !== $peerId) {
                yield $this->sendSignalingMessage($otherPeerId, [
                    'type' => 'peer_joined',
                    'peer_id' => $peerId,
                    'room_id' => $roomId
                ]);
            }
        }

        $this->logger->info('Peer joined room', [
            'peer_id' => $peerId,
            'room_id' => $roomId,
            'room_size' => count($this->rooms[$roomId])
        ]);
    }

    /**
     * Leave room
     */
    public function leaveChannel(string $peerId, string $roomId): \Generator
    {
        if (isset($this->rooms[$roomId][$peerId])) {
            unset($this->rooms[$roomId][$peerId]);
            
            if (empty($this->rooms[$roomId])) {
                unset($this->rooms[$roomId]);
                $this->metrics['rooms']--;
            } else {
                // Notify other peers
                foreach ($this->rooms[$roomId] as $otherPeerId => $otherPeer) {
                    yield $this->sendSignalingMessage($otherPeerId, [
                        'type' => 'peer_left',
                        'peer_id' => $peerId,
                        'room_id' => $roomId
                    ]);
                }
            }
        }

        if (isset($this->peers[$peerId])) {
            $this->peers[$peerId]->setRoomId(null);
        }
    }

    /**
     * Close peer connection
     */
    public function closeConnection(string $peerId, int $code = 1000, string $reason = ''): \Generator
    {
        if (!isset($this->peers[$peerId])) {
            return;
        }

        $peer = $this->peers[$peerId];
        $roomId = $peer->getRoomId();
        
        // Close all data channels
        foreach ($peer->getDataChannels() as $channel) {
            yield $channel->close();
            $channelId = $channel->getId();
            unset($this->channels[$channelId]);
            $this->metrics['active_channels']--;
        }

        // Remove from room
        if ($roomId) {
            yield $this->leaveChannel($peerId, $roomId);
        }

        // Close peer connection
        yield $peer->close($code, $reason);
        
        // Remove peer
        unset($this->peers[$peerId]);
        $this->metrics['active_peers']--;
    }

    /**
     * Start WebRTC server
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting WebRTC Data Channel server');

        // Start signaling server
        yield $this->signalingServer->start();
        
        // Start STUN/TURN server if enabled
        if ($this->config['enable_stun_turn']) {
            yield $this->stunTurnServer->start();
        }

        $this->isRunning = true;
        
        // Start monitoring
        \Amp\async(function() {
            yield $this->startMonitoring();
        });

        $this->logger->info('WebRTC Data Channel server started');
    }

    /**
     * Stop server
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping WebRTC Data Channel server');

        // Close all connections
        $closeFutures = [];
        foreach ($this->peers as $peer) {
            $closeFutures[] = $this->closeConnection($peer->getId());
        }

        if (!empty($closeFutures)) {
            yield \Amp\Future::awaitAll($closeFutures);
        }

        // Stop servers
        yield $this->signalingServer->stop();
        
        if ($this->config['enable_stun_turn']) {
            yield $this->stunTurnServer->stop();
        }

        $this->isRunning = false;
        
        $this->logger->info('WebRTC Data Channel server stopped');
    }

    /**
     * Get protocol name
     */
    public function getName(): string
    {
        return 'WebRTC-DataChannels';
    }

    /**
     * Get protocol version
     */
    public function getVersion(): string
    {
        return 'RFC8831';
    }

    /**
     * Get connection count
     */
    public function getConnectionCount(): int
    {
        return $this->metrics['active_peers'];
    }

    /**
     * Get connections in channel (room)
     */
    public function getChannelConnections(string $roomId): array
    {
        return array_keys($this->rooms[$roomId] ?? []);
    }

    /**
     * Get metrics
     */
    public function getMetrics(): array
    {
        // Update dynamic metrics
        $this->metrics['active_peers'] = count($this->peers);
        $this->metrics['active_channels'] = count($this->channels);
        $this->metrics['rooms'] = count($this->rooms);
        
        // Calculate P2P ratio
        $totalConnections = $this->metrics['p2p_connections'] + $this->metrics['server_relayed'];
        if ($totalConnections > 0) {
            $this->metrics['p2p_ratio'] = $this->metrics['p2p_connections'] / $totalConnections;
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
     * Helper methods
     */
    private function generatePeerId(): string
    {
        return 'peer-' . bin2hex(random_bytes(8));
    }

    private function generateChannelId(): string
    {
        return 'channel-' . bin2hex(random_bytes(8));
    }

    private function extractRoomId(Request $request): string
    {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        return $params['room'] ?? 'default';
    }

    private function generateICEServers(): array
    {
        $servers = [];
        
        if ($this->config['stun_servers']) {
            foreach ($this->config['stun_servers'] as $stunServer) {
                $servers[] = ['urls' => $stunServer];
            }
        }
        
        if ($this->config['turn_servers']) {
            foreach ($this->config['turn_servers'] as $turnServer) {
                $servers[] = [
                    'urls' => $turnServer['url'],
                    'username' => $turnServer['username'],
                    'credential' => $turnServer['credential']
                ];
            }
        }
        
        return $servers;
    }

    private function getDataChannelConfig(): array
    {
        return [
            'ordered' => $this->config['ordered'],
            'maxRetransmits' => $this->config['max_retransmits'],
            'maxPacketLifeTime' => $this->config['max_packet_lifetime'],
            'protocol' => $this->config['protocol'],
            'negotiated' => false
        ];
    }

    private function findTarget(string $connectionId)
    {
        return $this->channels[$connectionId] ?? $this->peers[$connectionId] ?? null;
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

    /**
     * Additional WebRTC-specific methods
     */
    private function relayICECandidate(WebRTCPeer $peer, array $candidate): \Generator
    {
        $roomId = $peer->getRoomId();
        if (!$roomId || !isset($this->rooms[$roomId])) {
            return;
        }

        // Relay to other peers in room
        foreach ($this->rooms[$roomId] as $otherPeerId => $otherPeer) {
            if ($otherPeerId !== $peer->getId()) {
                yield $this->sendSignalingMessage($otherPeerId, [
                    'type' => 'ice_candidate',
                    'candidate' => $candidate,
                    'from_peer' => $peer->getId()
                ]);
            }
        }

        $this->metrics['signaling_messages']++;
    }

    private function relaySDP(WebRTCPeer $peer, array $sdp): \Generator
    {
        $roomId = $peer->getRoomId();
        if (!$roomId || !isset($this->rooms[$roomId])) {
            return;
        }

        // Relay to other peers in room
        foreach ($this->rooms[$roomId] as $otherPeerId => $otherPeer) {
            if ($otherPeerId !== $peer->getId()) {
                yield $this->sendSignalingMessage($otherPeerId, [
                    'type' => 'sdp',
                    'sdp' => $sdp,
                    'from_peer' => $peer->getId()
                ]);
            }
        }

        $this->metrics['signaling_messages']++;
    }

    private function sendSignalingMessage(string $peerId, array $message): \Generator
    {
        if (isset($this->peers[$peerId])) {
            yield $this->peers[$peerId]->sendSignalingMessage($message);
        }
    }

    private function handleConnectionStateChange(WebRTCPeer $peer, string $state): \Generator
    {
        $this->logger->debug('Peer connection state changed', [
            'peer_id' => $peer->getId(),
            'state' => $state
        ]);

        if ($state === 'connected') {
            $this->metrics['nat_traversal_success']++;
        } elseif ($state === 'failed') {
            $this->metrics['connection_failures']++;
        }
    }

    private function handlePeerDisconnect(WebRTCPeer $peer, string $reason): \Generator
    {
        yield $this->closeConnection($peer->getId(), 1000, $reason);
        yield $this->triggerEvent('disconnect', $peer, 1000, $reason);
    }

    private function handleChannelStateChange(WebRTCDataChannel $channel, string $state): \Generator
    {
        $this->logger->debug('Data channel state changed', [
            'channel_id' => $channel->getId(),
            'state' => $state
        ]);
    }

    private function handleChannelError(WebRTCDataChannel $channel, \Throwable $error): \Generator
    {
        $this->logger->error('Data channel error', [
            'channel_id' => $channel->getId(),
            'error' => $error->getMessage()
        ]);
        
        yield $this->triggerEvent('error', $channel, $error);
    }

    private function startMonitoring(): \Generator
    {
        while ($this->isRunning) {
            yield \Amp\delay(30000); // 30 seconds
            
            try {
                // Update connection statistics
                foreach ($this->peers as $peer) {
                    $stats = $peer->getConnectionStats();
                    // Update bandwidth savings if P2P
                    if ($peer->isPeerToPeer()) {
                        $this->metrics['bandwidth_saved'] += $stats['bytes_transferred'] ?? 0;
                    }
                }
                
                // Cleanup dead connections
                yield $this->cleanupDeadConnections();
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in monitoring', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function cleanupDeadConnections(): \Generator
    {
        $deadPeers = [];
        
        foreach ($this->peers as $peerId => $peer) {
            if (!$peer->isActive()) {
                $deadPeers[] = $peerId;
            }
        }

        foreach ($deadPeers as $peerId) {
            yield $this->closeConnection($peerId, 1006, 'Connection timeout');
        }

        if (!empty($deadPeers)) {
            $this->logger->debug('Cleaned up dead peer connections', [
                'count' => count($deadPeers)
            ]);
        }
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enable_stun_turn' => true,
            'stun_servers' => [
                'stun:stun.l.google.com:19302',
                'stun:stun1.l.google.com:19302'
            ],
            'turn_servers' => [
                [
                    'url' => 'turn:your-turn-server.com:3478',
                    'username' => 'your-username',
                    'credential' => 'your-credential'
                ]
            ],
            'ordered' => true,
            'max_retransmits' => 3,
            'max_packet_lifetime' => 3000,
            'protocol' => 'json',
            'max_peers_per_room' => 10,
            'connection_timeout' => 30000,
            'signaling_timeout' => 10000,
            'enable_hybrid_relay' => true,
            'fallback_to_server' => true
        ];
    }
}