<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\Collaboration;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
use HighPerApp\HighPer\Realtime\Protocols\WebSocket\WebSocketHttp3Protocol;
use HighPerApp\HighPer\Realtime\Protocols\WebTransport\WebTransportProtocol;
use Psr\Log\LoggerInterface;

/**
 * Real-Time Collaborative Editing Protocol
 * 
 * Implements Operational Transform (OT) and Conflict-free Replicated Data Types (CRDT)
 * for real-time collaborative editing with automatic conflict resolution
 */
class CollaborativeEditingProtocol implements ProtocolInterface
{
    private LoggerInterface $logger;
    private array $config;
    private WebSocketHttp3Protocol $websocketProtocol;
    private WebTransportProtocol $webtransportProtocol;
    private OperationalTransform $operationalTransform;
    private CRDTManager $crdtManager;
    private ConflictResolver $conflictResolver;
    private DocumentStateManager $documentManager;
    
    private array $sessions = [];
    private array $documents = [];
    private array $participants = [];
    private array $eventHandlers = [];
    private bool $isRunning = false;
    private array $metrics = [];

    // Operation types
    private const OP_INSERT = 'insert';
    private const OP_DELETE = 'delete';
    private const OP_RETAIN = 'retain';
    private const OP_FORMAT = 'format';
    private const OP_CURSOR = 'cursor';

    public function __construct(
        LoggerInterface $logger,
        array $config,
        WebSocketHttp3Protocol $websocketProtocol,
        WebTransportProtocol $webtransportProtocol
    ) {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->websocketProtocol = $websocketProtocol;
        $this->webtransportProtocol = $webtransportProtocol;
        
        $this->initializeComponents();
        $this->initializeMetrics();
    }

    /**
     * Initialize collaborative editing components
     */
    private function initializeComponents(): void
    {
        $this->operationalTransform = new OperationalTransform($this->logger, $this->config);
        $this->crdtManager = new CRDTManager($this->logger, $this->config);
        $this->conflictResolver = new ConflictResolver($this->logger, $this->config);
        $this->documentManager = new DocumentStateManager($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'sessions' => 0,
            'active_sessions' => 0,
            'documents' => 0,
            'active_documents' => 0,
            'participants' => 0,
            'operations_applied' => 0,
            'conflicts_resolved' => 0,
            'transformations' => 0,
            'sync_events' => 0,
            'cursor_updates' => 0,
            'average_operation_time' => 0,
            'collaboration_efficiency' => 0
        ];
    }

    /**
     * Check if request supports collaborative editing
     */
    public function supports(Request $request): bool
    {
        $path = $request->getUri()->getPath();
        $protocolHeader = $request->getHeader('x-collaboration-protocol') ?? '';
        
        // Check for collaborative editing endpoints
        $isCollaborationRequest = strpos($path, '/collaborate') !== false ||
                                strpos($path, '/edit') !== false ||
                                strpos($path, '/document') !== false ||
                                !empty($protocolHeader);

        // Check if underlying protocols are supported
        $supportsWebSocket = $this->websocketProtocol->supports($request);
        $supportsWebTransport = $this->webtransportProtocol->supports($request);

        return $isCollaborationRequest && ($supportsWebSocket || $supportsWebTransport);
    }

    /**
     * Handle collaborative editing session handshake
     */
    public function handleHandshake(Request $request): \Generator
    {
        try {
            // Extract session parameters
            $documentId = $this->extractDocumentId($request);
            $userId = $this->extractUserId($request);
            $protocolPreference = $this->extractProtocolPreference($request);
            $permissions = $this->extractPermissions($request);
            
            // Validate permissions
            if (!$this->validatePermissions($userId, $documentId, $permissions)) {
                return new Response(403, [], 'Insufficient permissions');
            }

            // Determine best transport protocol
            $transportProtocol = yield $this->selectTransportProtocol($request, $protocolPreference);
            
            // Establish transport connection
            $transportConnection = yield $this->establishTransportConnection(
                $request, 
                $transportProtocol
            );

            // Create collaboration session
            $session = new CollaborationSession(
                $this->generateSessionId(),
                $documentId,
                $userId,
                $transportConnection,
                $this->logger,
                $this->config
            );

            // Store session
            $sessionId = $session->getId();
            $this->sessions[$sessionId] = $session;
            $this->metrics['sessions']++;
            $this->metrics['active_sessions']++;

            // Add to document participants
            yield $this->addParticipant($documentId, $session);

            // Setup session handlers
            yield $this->setupSessionHandlers($session);

            // Send session response
            $response = new Response(200, [
                'content-type' => 'application/json',
                'x-collaboration-protocol' => 'ot-crdt',
                'x-transport-protocol' => $transportProtocol
            ], json_encode([
                'session_id' => $sessionId,
                'document_id' => $documentId,
                'user_id' => $userId,
                'transport' => $transportProtocol,
                'features' => [
                    'operational_transform' => true,
                    'crdt' => $this->config['enable_crdt'],
                    'real_time_cursors' => true,
                    'presence_awareness' => true,
                    'conflict_resolution' => $this->config['conflict_resolution_algorithm']
                ],
                'document_state' => yield $this->getDocumentState($documentId),
                'participants' => $this->getDocumentParticipants($documentId)
            ]));

            $this->logger->info('Collaborative editing session established', [
                'session_id' => $sessionId,
                'document_id' => $documentId,
                'user_id' => $userId,
                'transport' => $transportProtocol,
                'participants_count' => count($this->getDocumentParticipants($documentId))
            ]);

            // Trigger connect event
            yield $this->triggerEvent('connect', $session);

            return $response;
            
        } catch (\Throwable $e) {
            $this->logger->error('Collaborative editing handshake failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            return new Response(500, [], 'Collaboration session setup failed');
        }
    }

    /**
     * Setup collaboration session handlers
     */
    private function setupSessionHandlers(CollaborationSession $session): \Generator
    {
        // Handle incoming operations
        $session->onOperation(function($operation) use ($session) {
            yield $this->handleOperation($session, $operation);
        });

        // Handle cursor updates
        $session->onCursorUpdate(function($cursor) use ($session) {
            yield $this->handleCursorUpdate($session, $cursor);
        });

        // Handle presence updates
        $session->onPresenceUpdate(function($presence) use ($session) {
            yield $this->handlePresenceUpdate($session, $presence);
        });

        // Handle session disconnect
        $session->onDisconnect(function($reason) use ($session) {
            yield $this->handleSessionDisconnect($session, $reason);
        });

        // Start session processing
        yield $session->start();
    }

    /**
     * Handle collaborative operation
     */
    private function handleOperation(CollaborationSession $session, array $operationData): \Generator
    {
        $startTime = microtime(true);
        
        try {
            $documentId = $session->getDocumentId();
            $userId = $session->getUserId();
            
            // Create operation object
            $operation = new CollaborativeOperation(
                $operationData['type'] ?? self::OP_INSERT,
                $operationData['position'] ?? 0,
                $operationData['content'] ?? '',
                $operationData['length'] ?? 0,
                $operationData['attributes'] ?? [],
                $userId,
                $operationData['version'] ?? 0
            );

            // Get current document state
            $documentState = yield $this->documentManager->getState($documentId);
            
            // Apply operational transform if needed
            if ($this->config['enable_operational_transform']) {
                $transformedOperation = yield $this->operationalTransform->transform(
                    $operation,
                    $documentState,
                    $this->getDocumentOperations($documentId)
                );
            } else {
                $transformedOperation = $operation;
            }

            // Apply CRDT if enabled
            if ($this->config['enable_crdt']) {
                $transformedOperation = yield $this->crdtManager->applyOperation(
                    $documentId,
                    $transformedOperation
                );
            }

            // Resolve conflicts
            $resolvedOperation = yield $this->conflictResolver->resolve(
                $transformedOperation,
                $documentState
            );

            // Apply operation to document
            $newState = yield $this->documentManager->applyOperation(
                $documentId,
                $resolvedOperation
            );

            // Broadcast to other participants
            yield $this->broadcastOperation($documentId, $resolvedOperation, $session->getId());

            // Update metrics
            $operationTime = microtime(true) - $startTime;
            $this->metrics['operations_applied']++;
            $this->metrics['transformations']++;
            $this->updateAverageOperationTime($operationTime);

            $this->logger->debug('Collaborative operation processed', [
                'session_id' => $session->getId(),
                'document_id' => $documentId,
                'operation_type' => $operation->getType(),
                'processing_time_ms' => round($operationTime * 1000, 2),
                'participants_notified' => count($this->getDocumentParticipants($documentId)) - 1
            ]);

            // Send acknowledgment to sender
            yield $session->sendAcknowledgment($resolvedOperation);
            
        } catch (\Throwable $e) {
            $this->logger->error('Error processing collaborative operation', [
                'session_id' => $session->getId(),
                'error' => $e->getMessage(),
                'operation_data' => $operationData
            ]);
            
            // Send error to client
            yield $session->sendError('operation_failed', $e->getMessage());
        }
    }

    /**
     * Handle cursor update
     */
    private function handleCursorUpdate(CollaborationSession $session, array $cursorData): \Generator
    {
        $documentId = $session->getDocumentId();
        $userId = $session->getUserId();
        
        // Create cursor update
        $cursorUpdate = new CursorUpdate(
            $userId,
            $cursorData['position'] ?? 0,
            $cursorData['selection_start'] ?? null,
            $cursorData['selection_end'] ?? null,
            $cursorData['color'] ?? $this->generateUserColor($userId),
            microtime(true)
        );

        // Broadcast cursor update to other participants
        yield $this->broadcastCursorUpdate($documentId, $cursorUpdate, $session->getId());
        
        $this->metrics['cursor_updates']++;
    }

    /**
     * Handle presence update
     */
    private function handlePresenceUpdate(CollaborationSession $session, array $presenceData): \Generator
    {
        $documentId = $session->getDocumentId();
        $userId = $session->getUserId();
        
        // Update participant presence
        $presence = new PresenceUpdate(
            $userId,
            $presenceData['status'] ?? 'active',
            $presenceData['activity'] ?? 'editing',
            $presenceData['viewport'] ?? null,
            microtime(true)
        );

        // Broadcast presence to other participants
        yield $this->broadcastPresenceUpdate($documentId, $presence, $session->getId());
    }

    /**
     * Broadcast operation to document participants
     */
    private function broadcastOperation(string $documentId, CollaborativeOperation $operation, string $excludeSessionId): \Generator
    {
        $participants = $this->getDocumentParticipants($documentId);
        
        foreach ($participants as $participantId => $participant) {
            if ($participantId !== $excludeSessionId) {
                try {
                    yield $participant->sendOperation($operation);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to send operation to participant', [
                        'participant_id' => $participantId,
                        'document_id' => $documentId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        $this->metrics['sync_events']++;
    }

    /**
     * Broadcast cursor update
     */
    private function broadcastCursorUpdate(string $documentId, CursorUpdate $cursorUpdate, string $excludeSessionId): \Generator
    {
        $participants = $this->getDocumentParticipants($documentId);
        
        foreach ($participants as $participantId => $participant) {
            if ($participantId !== $excludeSessionId) {
                try {
                    yield $participant->sendCursorUpdate($cursorUpdate);
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to send cursor update', [
                        'participant_id' => $participantId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Broadcast presence update
     */
    private function broadcastPresenceUpdate(string $documentId, PresenceUpdate $presence, string $excludeSessionId): \Generator
    {
        $participants = $this->getDocumentParticipants($documentId);
        
        foreach ($participants as $participantId => $participant) {
            if ($participantId !== $excludeSessionId) {
                try {
                    yield $participant->sendPresenceUpdate($presence);
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to send presence update', [
                        'participant_id' => $participantId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Send message (not directly applicable for collaborative editing)
     */
    public function sendMessage(string $sessionId, array $data): \Generator
    {
        if (!isset($this->sessions[$sessionId])) {
            throw new \InvalidArgumentException("Session {$sessionId} not found");
        }

        $session = $this->sessions[$sessionId];
        yield $session->sendMessage($data);
    }

    /**
     * Broadcast to multiple sessions
     */
    public function broadcast(array $sessionIds, array $data): \Generator
    {
        $futures = [];
        
        foreach ($sessionIds as $sessionId) {
            if (isset($this->sessions[$sessionId])) {
                $futures[] = $this->sendMessage($sessionId, $data);
            }
        }

        if (!empty($futures)) {
            yield \Amp\Future::awaitAll($futures);
        }
    }

    /**
     * Broadcast to document (channel)
     */
    public function broadcastToChannel(string $documentId, array $data): \Generator
    {
        $participants = $this->getDocumentParticipants($documentId);
        $sessionIds = array_keys($participants);
        yield $this->broadcast($sessionIds, $data);
    }

    /**
     * Join session to document
     */
    public function joinChannel(string $sessionId, string $documentId): \Generator
    {
        if (!isset($this->sessions[$sessionId])) {
            throw new \InvalidArgumentException("Session {$sessionId} not found");
        }

        $session = $this->sessions[$sessionId];
        yield $this->addParticipant($documentId, $session);
    }

    /**
     * Leave document
     */
    public function leaveChannel(string $sessionId, string $documentId): \Generator
    {
        yield $this->removeParticipant($documentId, $sessionId);
    }

    /**
     * Close session
     */
    public function closeConnection(string $sessionId, int $code = 1000, string $reason = ''): \Generator
    {
        if (!isset($this->sessions[$sessionId])) {
            return;
        }

        $session = $this->sessions[$sessionId];
        yield $session->close($reason);
    }

    /**
     * Start collaborative editing server
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting Collaborative Editing server');

        // Start underlying protocols
        yield $this->websocketProtocol->start();
        yield $this->webtransportProtocol->start();

        $this->isRunning = true;
        
        // Start maintenance tasks
        \Amp\async(function() {
            yield $this->startMaintenanceTasks();
        });

        $this->logger->info('Collaborative Editing server started');
    }

    /**
     * Stop server
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return;
        }

        $this->logger->info('Stopping Collaborative Editing server');

        // Close all sessions
        $closeFutures = [];
        foreach ($this->sessions as $session) {
            $closeFutures[] = $this->closeConnection($session->getId());
        }

        if (!empty($closeFutures)) {
            yield \Amp\Future::awaitAll($closeFutures);
        }

        $this->isRunning = false;
        
        $this->logger->info('Collaborative Editing server stopped');
    }

    /**
     * Get protocol name
     */
    public function getName(): string
    {
        return 'CollaborativeEditing';
    }

    /**
     * Get protocol version
     */
    public function getVersion(): string
    {
        return 'OT-CRDT-1.0';
    }

    /**
     * Get connection count
     */
    public function getConnectionCount(): int
    {
        return $this->metrics['active_sessions'];
    }

    /**
     * Get connections in channel (document)
     */
    public function getChannelConnections(string $documentId): array
    {
        return array_keys($this->getDocumentParticipants($documentId));
    }

    /**
     * Get metrics
     */
    public function getMetrics(): array
    {
        // Update dynamic metrics
        $this->metrics['active_sessions'] = count($this->sessions);
        $this->metrics['active_documents'] = count($this->documents);
        $this->metrics['participants'] = array_sum(array_map(
            fn($doc) => count($doc['participants']), 
            $this->documents
        ));

        // Calculate collaboration efficiency
        $totalOperations = $this->metrics['operations_applied'];
        $totalConflicts = $this->metrics['conflicts_resolved'];
        
        if ($totalOperations > 0) {
            $this->metrics['collaboration_efficiency'] = 
                1 - ($totalConflicts / $totalOperations);
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
    private function extractDocumentId(Request $request): string
    {
        $path = $request->getUri()->getPath();
        if (preg_match('/\/document\/([^\/]+)/', $path, $matches)) {
            return $matches[1];
        }
        
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        return $params['document'] ?? 'default';
    }

    private function extractUserId(Request $request): string
    {
        return $request->getHeader('x-user-id') ?? 
               $request->getHeader('authorization') ?? 
               'anonymous-' . bin2hex(random_bytes(4));
    }

    private function extractProtocolPreference(Request $request): string
    {
        return $request->getHeader('x-preferred-transport') ?? 'auto';
    }

    private function extractPermissions(Request $request): array
    {
        $permissions = $request->getHeader('x-permissions') ?? 'read,write';
        return explode(',', $permissions);
    }

    private function validatePermissions(string $userId, string $documentId, array $permissions): bool
    {
        // Implement your permission validation logic here
        return in_array('read', $permissions) || in_array('write', $permissions);
    }

    private function selectTransportProtocol(Request $request, string $preference): \Generator
    {
        if ($preference === 'webtransport' && $this->webtransportProtocol->supports($request)) {
            return 'webtransport';
        }
        
        if ($preference === 'websocket' && $this->websocketProtocol->supports($request)) {
            return 'websocket-http3';
        }
        
        // Auto-select best protocol
        if ($this->webtransportProtocol->supports($request)) {
            return 'webtransport'; // Preferred for collaboration
        }
        
        return 'websocket-http3';
    }

    private function establishTransportConnection(Request $request, string $protocol): \Generator
    {
        if ($protocol === 'webtransport') {
            return yield $this->webtransportProtocol->handleHandshake($request);
        } else {
            return yield $this->websocketProtocol->handleHandshake($request);
        }
    }

    private function generateSessionId(): string
    {
        return 'collab-' . bin2hex(random_bytes(12));
    }

    private function addParticipant(string $documentId, CollaborationSession $session): \Generator
    {
        if (!isset($this->documents[$documentId])) {
            $this->documents[$documentId] = [
                'participants' => [],
                'state' => yield $this->documentManager->createDocument($documentId),
                'operations' => []
            ];
            $this->metrics['documents']++;
        }

        $this->documents[$documentId]['participants'][$session->getId()] = $session;
        
        // Notify other participants
        yield $this->broadcastPresenceUpdate($documentId, new PresenceUpdate(
            $session->getUserId(),
            'joined',
            'editing',
            null,
            microtime(true)
        ), $session->getId());
    }

    private function removeParticipant(string $documentId, string $sessionId): \Generator
    {
        if (isset($this->documents[$documentId]['participants'][$sessionId])) {
            $session = $this->documents[$documentId]['participants'][$sessionId];
            unset($this->documents[$documentId]['participants'][$sessionId]);
            
            // Notify other participants
            yield $this->broadcastPresenceUpdate($documentId, new PresenceUpdate(
                $session->getUserId(),
                'left',
                'inactive',
                null,
                microtime(true)
            ), $sessionId);
            
            // Clean up empty documents
            if (empty($this->documents[$documentId]['participants'])) {
                unset($this->documents[$documentId]);
                $this->metrics['documents']--;
            }
        }
    }

    private function getDocumentParticipants(string $documentId): array
    {
        return $this->documents[$documentId]['participants'] ?? [];
    }

    private function getDocumentState(string $documentId): \Generator
    {
        if (!isset($this->documents[$documentId])) {
            return yield $this->documentManager->createDocument($documentId);
        }
        
        return $this->documents[$documentId]['state'];
    }

    private function getDocumentOperations(string $documentId): array
    {
        return $this->documents[$documentId]['operations'] ?? [];
    }

    private function generateUserColor(string $userId): string
    {
        $colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FECA57', '#FF9FF3', '#54A0FF'];
        $hash = crc32($userId);
        return $colors[abs($hash) % count($colors)];
    }

    private function updateAverageOperationTime(float $time): void
    {
        if ($this->metrics['average_operation_time'] === 0) {
            $this->metrics['average_operation_time'] = $time;
        } else {
            $alpha = 0.1;
            $this->metrics['average_operation_time'] = 
                (1 - $alpha) * $this->metrics['average_operation_time'] + $alpha * $time;
        }
    }

    private function handleSessionDisconnect(CollaborationSession $session, string $reason): \Generator
    {
        $sessionId = $session->getId();
        $documentId = $session->getDocumentId();
        
        // Remove from document
        yield $this->removeParticipant($documentId, $sessionId);
        
        // Remove session
        unset($this->sessions[$sessionId]);
        $this->metrics['active_sessions']--;
        
        // Trigger disconnect event
        yield $this->triggerEvent('disconnect', $session, $reason);
        
        $this->logger->info('Collaboration session disconnected', [
            'session_id' => $sessionId,
            'document_id' => $documentId,
            'reason' => $reason
        ]);
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

    private function startMaintenanceTasks(): \Generator
    {
        while ($this->isRunning) {
            yield \Amp\delay(30000); // 30 seconds
            
            try {
                // Cleanup inactive sessions
                yield $this->cleanupInactiveSessions();
                
                // Optimize document storage
                yield $this->optimizeDocumentStorage();
                
                // Update collaboration metrics
                yield $this->updateCollaborationMetrics();
                
            } catch (\Throwable $e) {
                $this->logger->error('Error in collaborative editing maintenance', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function cleanupInactiveSessions(): \Generator
    {
        $inactiveSessions = [];
        
        foreach ($this->sessions as $sessionId => $session) {
            if (!$session->isActive()) {
                $inactiveSessions[] = $sessionId;
            }
        }

        foreach ($inactiveSessions as $sessionId) {
            yield $this->closeConnection($sessionId, 1006, 'Session timeout');
        }

        if (!empty($inactiveSessions)) {
            $this->logger->debug('Cleaned up inactive collaboration sessions', [
                'count' => count($inactiveSessions)
            ]);
        }
    }

    private function optimizeDocumentStorage(): \Generator
    {
        // Implement document storage optimization
        // e.g., compact operation history, save snapshots, etc.
    }

    private function updateCollaborationMetrics(): \Generator
    {
        // Update collaboration-specific metrics
        // e.g., average participants per document, operation frequency, etc.
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enable_operational_transform' => true,
            'enable_crdt' => true,
            'conflict_resolution_algorithm' => 'last_writer_wins',
            'max_operations_history' => 1000,
            'document_snapshot_interval' => 100, // operations
            'session_timeout' => 300, // seconds
            'max_participants_per_document' => 50,
            'real_time_cursors' => true,
            'presence_awareness' => true,
            'operation_timeout' => 5000, // milliseconds
            'enable_document_locking' => false
        ];
    }
}