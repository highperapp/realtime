<?php

declare(strict_types=1);

namespace EaseAppPHP\HighPer\Realtime\Protocols;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

interface ProtocolInterface
{
    /**
     * Check if the request is compatible with this protocol
     */
    public function supports(Request $request): bool;

    /**
     * Handle the protocol handshake/upgrade
     */
    public function handleHandshake(Request $request): \Generator;

    /**
     * Send a message to a connection
     */
    public function sendMessage(string $connectionId, array $data): \Generator;

    /**
     * Broadcast a message to multiple connections
     */
    public function broadcast(array $connectionIds, array $data): \Generator;

    /**
     * Broadcast to all connections in a channel/room
     */
    public function broadcastToChannel(string $channel, array $data): \Generator;

    /**
     * Join a connection to a channel/room
     */
    public function joinChannel(string $connectionId, string $channel): \Generator;

    /**
     * Leave a channel/room
     */
    public function leaveChannel(string $connectionId, string $channel): \Generator;

    /**
     * Close a connection
     */
    public function closeConnection(string $connectionId, int $code = 1000, string $reason = ''): \Generator;

    /**
     * Get protocol-specific metrics
     */
    public function getMetrics(): array;

    /**
     * Get active connections count
     */
    public function getConnectionCount(): int;

    /**
     * Get connections in a specific channel
     */
    public function getChannelConnections(string $channel): array;

    /**
     * Register event handlers
     */
    public function onConnect(callable $handler): void;
    public function onDisconnect(callable $handler): void;
    public function onMessage(callable $handler): void;
    public function onError(callable $handler): void;

    /**
     * Start the protocol server
     */
    public function start(): \Generator;

    /**
     * Stop the protocol server
     */
    public function stop(): \Generator;

    /**
     * Get protocol name
     */
    public function getName(): string;

    /**
     * Get protocol version
     */
    public function getVersion(): string;
}