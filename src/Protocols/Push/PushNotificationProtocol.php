<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Realtime\Protocols\Push;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use HighPerApp\HighPer\Realtime\Protocols\ProtocolInterface;
use HighPerApp\HighPer\Realtime\Protocols\PWA\PWARealtimeProtocol;
use Psr\Log\LoggerInterface;

/**
 * Push Notification Integration Protocol
 * 
 * Implements comprehensive push notification support for offline users:
 * - Web Push API integration with VAPID authentication
 * - Firebase Cloud Messaging (FCM) support
 * - Apple Push Notification Service (APNS) integration
 * - Intelligent fallback from real-time to push notifications
 * - Offline user detection and notification queuing
 * - Rich notification content with actions and images
 * - Notification analytics and delivery tracking
 * - Cross-platform notification orchestration
 */
class PushNotificationProtocol implements ProtocolInterface
{
    private LoggerInterface $logger;
    private array $config;
    private PWARealtimeProtocol $pwaProtocol;
    private WebPushManager $webPushManager;
    private FCMManager $fcmManager;
    private APNSManager $apnsManager;
    private NotificationQueue $notificationQueue;
    private OfflineDetector $offlineDetector;
    private NotificationAnalytics $analytics;
    private VAPIDManager $vapidManager;
    
    private array $subscriptions = [];
    private array $deviceTokens = [];
    private array $offlineUsers = [];
    private array $pendingNotifications = [];
    private array $eventHandlers = [];
    private bool $isRunning = false;
    private array $metrics = [];

    // Notification types
    private const TYPE_MESSAGE = 'message';
    private const TYPE_ALERT = 'alert';
    private const TYPE_UPDATE = 'update';
    private const TYPE_REMINDER = 'reminder';
    private const TYPE_SOCIAL = 'social';
    private const TYPE_SYSTEM = 'system';

    // Priority levels
    private const PRIORITY_LOW = 'low';
    private const PRIORITY_NORMAL = 'normal';
    private const PRIORITY_HIGH = 'high';
    private const PRIORITY_CRITICAL = 'critical';

    // Delivery strategies
    private const DELIVERY_IMMEDIATE = 'immediate';
    private const DELIVERY_BATCHED = 'batched';
    private const DELIVERY_SCHEDULED = 'scheduled';
    private const DELIVERY_SMART = 'smart';

    public function __construct(
        LoggerInterface $logger,
        array $config,
        PWARealtimeProtocol $pwaProtocol = null
    ) {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->pwaProtocol = $pwaProtocol;
        
        $this->initializeComponents();
        $this->initializeMetrics();
    }

    /**
     * Initialize push notification components
     */
    private function initializeComponents(): void
    {
        $this->webPushManager = new WebPushManager($this->logger, $this->config);
        $this->fcmManager = new FCMManager($this->logger, $this->config);
        $this->apnsManager = new APNSManager($this->logger, $this->config);
        $this->notificationQueue = new NotificationQueue($this->logger, $this->config);
        $this->offlineDetector = new OfflineDetector($this->logger, $this->config);
        $this->analytics = new NotificationAnalytics($this->logger, $this->config);
        $this->vapidManager = new VAPIDManager($this->logger, $this->config);
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void
    {
        $this->metrics = [
            'push_subscriptions' => 0,
            'active_subscriptions' => 0,
            'notifications_sent' => 0,
            'notifications_delivered' => 0,
            'notifications_failed' => 0,
            'offline_users_detected' => 0,
            'fallback_activations' => 0,
            'delivery_rate' => 0,
            'click_through_rate' => 0,
            'opt_out_rate' => 0,
            'platform_distribution' => [
                'web' => 0,
                'android' => 0,
                'ios' => 0,
                'desktop' => 0
            ],
            'notification_types' => array_fill_keys([
                self::TYPE_MESSAGE,
                self::TYPE_ALERT,
                self::TYPE_UPDATE,
                self::TYPE_REMINDER,
                self::TYPE_SOCIAL,
                self::TYPE_SYSTEM
            ], 0),
            'priority_distribution' => array_fill_keys([
                self::PRIORITY_LOW,
                self::PRIORITY_NORMAL,
                self::PRIORITY_HIGH,
                self::PRIORITY_CRITICAL
            ], 0),
            'average_delivery_time' => 0,
            'queue_size' => 0
        ];
    }

    /**
     * Check if request supports push notifications
     */
    public function supports(Request $request): bool
    {
        $path = $request->getUri()->getPath();
        $userAgent = strtolower($request->getHeader('user-agent') ?? '');
        
        // Check for push notification endpoints
        $isPushRequest = strpos($path, '/push') !== false ||
                        strpos($path, '/subscribe') !== false ||
                        strpos($path, '/notification') !== false ||
                        $request->hasHeader('x-push-subscription');

        // Check for push-capable browsers/platforms
        $supportsPush = strpos($userAgent, 'chrome') !== false ||
                       strpos($userAgent, 'firefox') !== false ||
                       strpos($userAgent, 'safari') !== false ||
                       strpos($userAgent, 'edge') !== false ||
                       strpos($userAgent, 'android') !== false ||
                       strpos($userAgent, 'ios') !== false;

        return $isPushRequest && $supportsPush;
    }

    /**
     * Handle push notification handshake
     */
    public function handleHandshake(Request $request): \Generator
    {
        try {
            $path = $request->getUri()->getPath();
            
            // Route to appropriate handler
            if (strpos($path, '/push/subscribe') !== false) {
                return yield $this->handleSubscription($request);
            } elseif (strpos($path, '/push/unsubscribe') !== false) {
                return yield $this->handleUnsubscription($request);
            } elseif (strpos($path, '/push/send') !== false) {
                return yield $this->handleNotificationSend($request);
            } elseif (strpos($path, '/push/vapid') !== false) {
                return yield $this->handleVAPIDKeys($request);
            } elseif (strpos($path, '/push/analytics') !== false) {
                return yield $this->handleAnalytics($request);
            } else {
                return yield $this->handleGenericPushHandshake($request);
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Push notification handshake failed', [
                'error' => $e->getMessage(),
                'uri' => (string)$request->getUri()
            ]);
            
            return new Response(500, [], 'Push notification handshake failed');
        }
    }

    /**
     * Handle push subscription
     */
    private function handleSubscription(Request $request): \Generator
    {
        $subscriptionData = json_decode($request->getBody()->buffer(), true);
        
        if (!$subscriptionData || !isset($subscriptionData['endpoint'])) {
            return new Response(400, [], 'Invalid subscription data');
        }

        // Extract subscription details
        $endpoint = $subscriptionData['endpoint'];
        $keys = $subscriptionData['keys'] ?? [];
        $userId = $request->getHeader('x-user-id') ?? 'anonymous';
        $platform = $this->detectPlatform($request);
        
        // Create subscription
        $subscription = new PushSubscription(
            $this->generateSubscriptionId(),
            $userId,
            $endpoint,
            $keys,
            $platform,
            $this->logger,
            $this->config
        );

        // Validate subscription
        $validationResult = yield $this->validateSubscription($subscription);
        
        if (!$validationResult['valid']) {
            return new Response(400, [], 'Subscription validation failed: ' . $validationResult['reason']);
        }

        // Store subscription
        $subscriptionId = $subscription->getId();
        $this->subscriptions[$subscriptionId] = $subscription;
        $this->metrics['push_subscriptions']++;
        $this->metrics['active_subscriptions']++;
        $this->metrics['platform_distribution'][$platform]++;

        // Setup offline detection for this subscription
        yield $this->setupOfflineDetection($subscription);

        // Send test notification if configured
        if ($this->config['send_welcome_notification']) {
            yield $this->sendWelcomeNotification($subscription);
        }

        $response = new Response(200, [
            'content-type' => 'application/json'
        ], json_encode([
            'type' => 'subscription_success',
            'subscription_id' => $subscriptionId,
            'user_id' => $userId,
            'platform' => $platform,
            'features' => [
                'rich_notifications' => true,
                'action_buttons' => true,
                'images' => true,
                'badge' => true,
                'offline_support' => true,
                'analytics' => true
            ],
            'vapid_public_key' => $this->vapidManager->getPublicKey(),
            'endpoint_info' => $this->getEndpointInfo($endpoint)
        ]));

        $this->logger->info('Push notification subscription created', [
            'subscription_id' => $subscriptionId,
            'user_id' => $userId,
            'platform' => $platform,
            'endpoint' => substr($endpoint, 0, 50) . '...'
        ]);

        // Trigger subscription event
        yield $this->triggerEvent('subscription_created', $subscription);

        return $response;
    }

    /**
     * Handle notification sending
     */
    private function handleNotificationSend(Request $request): \Generator
    {
        $notificationData = json_decode($request->getBody()->buffer(), true);
        
        if (!$notificationData) {
            return new Response(400, [], 'Invalid notification data');
        }

        // Create notification
        $notification = new PushNotification(
            $this->generateNotificationId(),
            $notificationData['title'] ?? 'Notification',
            $notificationData['body'] ?? '',
            $notificationData['type'] ?? self::TYPE_MESSAGE,
            $notificationData['priority'] ?? self::PRIORITY_NORMAL,
            $notificationData['data'] ?? [],
            $this->logger,
            $this->config
        );

        // Set optional properties
        if (isset($notificationData['icon'])) {
            $notification->setIcon($notificationData['icon']);
        }
        if (isset($notificationData['badge'])) {
            $notification->setBadge($notificationData['badge']);
        }
        if (isset($notificationData['image'])) {
            $notification->setImage($notificationData['image']);
        }
        if (isset($notificationData['actions'])) {
            $notification->setActions($notificationData['actions']);
        }
        if (isset($notificationData['tag'])) {
            $notification->setTag($notificationData['tag']);
        }

        // Determine recipients
        $recipients = $this->determineRecipients($notificationData);
        
        // Send notification
        $sendResult = yield $this->sendNotification($notification, $recipients);

        $this->metrics['notifications_sent']++;
        $this->metrics['notification_types'][$notification->getType()]++;
        $this->metrics['priority_distribution'][$notification->getPriority()]++;

        return new Response(200, [
            'content-type' => 'application/json'
        ], json_encode([
            'type' => 'notification_sent',
            'notification_id' => $notification->getId(),
            'recipients_count' => count($recipients),
            'delivery_results' => $sendResult,
            'queued_for_offline' => $sendResult['queued_count'] ?? 0
        ]));
    }

    /**
     * Send notification to recipients
     */
    private function sendNotification(PushNotification $notification, array $recipients): \Generator
    {
        $deliveryResults = [
            'successful' => 0,
            'failed' => 0,
            'queued_count' => 0,
            'details' => []
        ];

        foreach ($recipients as $recipientId) {
            $startTime = microtime(true);
            
            try {
                // Check if user is online (connected to real-time)
                $isOnline = $this->isUserOnline($recipientId);
                
                if ($isOnline && $this->shouldUseRealtimeInsteadOfPush($notification)) {
                    // Send via real-time if user is online and notification allows it
                    yield $this->sendViaRealtime($recipientId, $notification);
                    $deliveryResults['successful']++;
                } else {
                    // Send via push notification
                    $pushResult = yield $this->sendPushToUser($recipientId, $notification);
                    
                    if ($pushResult['success']) {
                        $deliveryResults['successful']++;
                        $this->metrics['notifications_delivered']++;
                    } else {
                        if ($pushResult['reason'] === 'offline') {
                            // Queue for when user comes online
                            yield $this->queueNotificationForOfflineUser($recipientId, $notification);
                            $deliveryResults['queued_count']++;
                        } else {
                            $deliveryResults['failed']++;
                            $this->metrics['notifications_failed']++;
                        }
                    }
                }

                $deliveryTime = microtime(true) - $startTime;
                $this->updateAverageDeliveryTime($deliveryTime);
                
                $deliveryResults['details'][] = [
                    'recipient_id' => $recipientId,
                    'success' => $pushResult['success'] ?? true,
                    'method' => $isOnline ? 'realtime' : 'push',
                    'delivery_time_ms' => round($deliveryTime * 1000, 2)
                ];

            } catch (\Throwable $e) {
                $deliveryResults['failed']++;
                $this->metrics['notifications_failed']++;
                
                $deliveryResults['details'][] = [
                    'recipient_id' => $recipientId,
                    'success' => false,
                    'error' => $e->getMessage()
                ];

                $this->logger->error('Failed to send notification', [
                    'notification_id' => $notification->getId(),
                    'recipient_id' => $recipientId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Update delivery rate
        $totalSent = $deliveryResults['successful'] + $deliveryResults['failed'];
        if ($totalSent > 0) {
            $currentRate = $deliveryResults['successful'] / $totalSent;
            $this->metrics['delivery_rate'] = ($this->metrics['delivery_rate'] + $currentRate) / 2;
        }

        return $deliveryResults;
    }

    /**
     * Send push notification to specific user
     */
    private function sendPushToUser(string $userId, PushNotification $notification): \Generator
    {
        // Find user's subscriptions
        $userSubscriptions = $this->getUserSubscriptions($userId);
        
        if (empty($userSubscriptions)) {
            return ['success' => false, 'reason' => 'no_subscriptions'];
        }

        $sendResults = [];
        
        foreach ($userSubscriptions as $subscription) {
            try {
                // Select appropriate push service based on platform
                $pushResult = yield $this->sendToSubscription($subscription, $notification);
                $sendResults[] = $pushResult;
                
                if ($pushResult['success']) {
                    // Track successful delivery
                    yield $this->analytics->trackDelivery($notification, $subscription);
                }

            } catch (\Throwable $e) {
                $sendResults[] = ['success' => false, 'error' => $e->getMessage()];
                
                $this->logger->warning('Failed to send to subscription', [
                    'subscription_id' => $subscription->getId(),
                    'notification_id' => $notification->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Return success if at least one delivery succeeded
        $hasSuccess = array_filter($sendResults, fn($result) => $result['success']);
        
        return [
            'success' => !empty($hasSuccess),
            'results' => $sendResults,
            'reason' => empty($hasSuccess) ? 'all_failed' : 'success'
        ];
    }

    /**
     * Send to specific subscription
     */
    private function sendToSubscription(PushSubscription $subscription, PushNotification $notification): \Generator
    {
        $platform = $subscription->getPlatform();
        
        switch ($platform) {
            case 'web':
                return yield $this->webPushManager->send($subscription, $notification);
                
            case 'android':
                return yield $this->fcmManager->send($subscription, $notification);
                
            case 'ios':
                return yield $this->apnsManager->send($subscription, $notification);
                
            default:
                // Fallback to web push
                return yield $this->webPushManager->send($subscription, $notification);
        }
    }

    /**
     * Queue notification for offline user
     */
    private function queueNotificationForOfflineUser(string $userId, PushNotification $notification): \Generator
    {
        yield $this->notificationQueue->add($userId, $notification);
        
        if (!in_array($userId, $this->offlineUsers)) {
            $this->offlineUsers[] = $userId;
            $this->metrics['offline_users_detected']++;
        }

        $this->metrics['queue_size'] = yield $this->notificationQueue->getSize();
    }

    /**
     * Send message (notification)
     */
    public function sendMessage(string $connectionId, array $data): \Generator
    {
        // Convert data to notification format
        $notification = new PushNotification(
            $this->generateNotificationId(),
            $data['title'] ?? 'Message',
            $data['body'] ?? json_encode($data),
            $data['type'] ?? self::TYPE_MESSAGE,
            $data['priority'] ?? self::PRIORITY_NORMAL,
            $data,
            $this->logger,
            $this->config
        );

        // Send to specific connection/user
        $sendResult = yield $this->sendNotification($notification, [$connectionId]);
        
        return $sendResult;
    }

    /**
     * Broadcast notification
     */
    public function broadcast(array $connectionIds, array $data): \Generator
    {
        $notification = new PushNotification(
            $this->generateNotificationId(),
            $data['title'] ?? 'Broadcast',
            $data['body'] ?? json_encode($data),
            $data['type'] ?? self::TYPE_MESSAGE,
            $data['priority'] ?? self::PRIORITY_NORMAL,
            $data,
            $this->logger,
            $this->config
        );

        return yield $this->sendNotification($notification, $connectionIds);
    }

    /**
     * Broadcast to channel
     */
    public function broadcastToChannel(string $channel, array $data): \Generator
    {
        $connectionIds = $this->getChannelConnections($channel);
        return yield $this->broadcast($connectionIds, $data);
    }

    /**
     * Join channel
     */
    public function joinChannel(string $connectionId, string $channel): \Generator
    {
        // For push notifications, we track channel memberships separately
        // as connections might be offline
        yield $this->notificationQueue->addToChannel($connectionId, $channel);
    }

    /**
     * Leave channel
     */
    public function leaveChannel(string $connectionId, string $channel): \Generator
    {
        yield $this->notificationQueue->removeFromChannel($connectionId, $channel);
    }

    /**
     * Close connection (unsubscribe)
     */
    public function closeConnection(string $connectionId, int $code = 1000, string $reason = ''): \Generator
    {
        // Remove subscription
        if (isset($this->subscriptions[$connectionId])) {
            $subscription = $this->subscriptions[$connectionId];
            
            yield $this->unsubscribe($subscription);
            
            unset($this->subscriptions[$connectionId]);
            $this->metrics['active_subscriptions']--;
        }
    }

    /**
     * Start push notification service
     */
    public function start(): \Generator
    {
        if ($this->isRunning) {
            return;
        }

        $this->logger->info('Starting Push Notification service');

        // Start push managers
        yield $this->webPushManager->start();
        yield $this->fcmManager->start();
        yield $this->apnsManager->start();
        
        // Start supporting services
        yield $this->notificationQueue->start();
        yield $this->offlineDetector->start();
        yield $this->analytics->start();

        $this->isRunning = true;
        
        // Start monitoring and processing
        \Amp\async(function() {
            yield $this->startPushMonitoring();
        });

        \Amp\async(function() {
            yield $this->processOfflineQueue();
        });

        $this->logger->info('Push Notification service started');
    }

    /**
     * Stop service
     */
    public function stop(): \Generator
    {
        if (!$this->isRunning) {
            return yield;
        }

        $this->logger->info('Stopping Push Notification service');

        $this->isRunning = false;
        
        $this->logger->info('Push Notification service stopped');
        
        return yield;
    }

    /**
     * Get protocol name
     */
    public function getName(): string
    {
        return 'PushNotification';
    }

    /**
     * Get protocol version
     */
    public function getVersion(): string
    {
        return 'WebPush-FCM-APNS-1.0';
    }

    /**
     * Get connection count
     */
    public function getConnectionCount(): int
    {
        return count($this->subscriptions);
    }

    /**
     * Get connections in channel
     */
    public function getChannelConnections(string $channel): array
    {
        // Return user IDs subscribed to channel
        return $this->notificationQueue->getChannelMembers($channel);
    }

    /**
     * Get metrics
     */
    public function getMetrics(): array
    {
        // Update dynamic metrics
        $this->metrics['active_subscriptions'] = count($this->subscriptions);
        $this->metrics['queue_size'] = count($this->pendingNotifications);

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
     * Push-specific event handlers
     */
    public function onNotificationDelivered(callable $handler): void
    {
        $this->eventHandlers['notification_delivered'][] = $handler;
    }

    public function onSubscriptionExpired(callable $handler): void
    {
        $this->eventHandlers['subscription_expired'][] = $handler;
    }

    public function onOfflineUserDetected(callable $handler): void
    {
        $this->eventHandlers['offline_user_detected'][] = $handler;
    }

    /**
     * Helper methods
     */
    private function generateSubscriptionId(): string
    {
        return 'push-sub-' . bin2hex(random_bytes(8));
    }

    private function generateNotificationId(): string
    {
        return 'notif-' . bin2hex(random_bytes(6));
    }

    private function detectPlatform(Request $request): string
    {
        $userAgent = strtolower($request->getHeader('user-agent') ?? '');
        
        if (strpos($userAgent, 'android') !== false) {
            return 'android';
        } elseif (strpos($userAgent, 'iphone') !== false || strpos($userAgent, 'ipad') !== false) {
            return 'ios';
        } elseif (strpos($userAgent, 'electron') !== false) {
            return 'desktop';
        } else {
            return 'web';
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
                $this->logger->error('Error in push event handler', [
                    'event' => $event,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function updateAverageDeliveryTime(float $time): void
    {
        if ($this->metrics['average_delivery_time'] === 0) {
            $this->metrics['average_delivery_time'] = $time;
        } else {
            $alpha = 0.1;
            $this->metrics['average_delivery_time'] = 
                (1 - $alpha) * $this->metrics['average_delivery_time'] + $alpha * $time;
        }
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'send_welcome_notification' => true,
            'max_notification_queue_size' => 1000,
            'notification_ttl' => 86400, // 24 hours
            'batch_size' => 100,
            'retry_attempts' => 3,
            'retry_delay' => 5000, // 5 seconds
            'enable_analytics' => true,
            'vapid_subject' => 'mailto:admin@example.com',
            'fcm_api_key' => '',
            'apns_key_file' => '',
            'apns_key_id' => '',
            'apns_team_id' => ''
        ];
    }

    // Placeholder methods for full implementation
    private function validateSubscription(PushSubscription $subscription): \Generator { return yield ['valid' => true]; }
    private function setupOfflineDetection(PushSubscription $subscription): \Generator { return yield; }
    private function sendWelcomeNotification(PushSubscription $subscription): \Generator { return yield; }
    private function getEndpointInfo(string $endpoint): array { return ['provider' => 'unknown']; }
    private function handleUnsubscription(Request $request): \Generator { return yield new Response(200, [], '{}'); }
    private function handleVAPIDKeys(Request $request): \Generator { return yield new Response(200, [], '{}'); }
    private function handleAnalytics(Request $request): \Generator { return yield new Response(200, [], '{}'); }
    private function handleGenericPushHandshake(Request $request): \Generator { return yield new Response(200, [], '{}'); }
    private function determineRecipients(array $notificationData): array { return $notificationData['recipients'] ?? []; }
    private function isUserOnline(string $userId): bool { return false; }
    private function shouldUseRealtimeInsteadOfPush(PushNotification $notification): bool { return false; }
    private function sendViaRealtime(string $userId, PushNotification $notification): \Generator { return yield; }
    private function getUserSubscriptions(string $userId): array { return array_filter($this->subscriptions, fn($sub) => $sub->getUserId() === $userId); }
    private function unsubscribe(PushSubscription $subscription): \Generator { return yield; }
    private function startPushMonitoring(): \Generator 
    { 
        while ($this->isRunning) {
            yield \Amp\delay(60000);
            // Monitor subscription health, cleanup expired, etc.
        }
    }
    private function processOfflineQueue(): \Generator 
    { 
        while ($this->isRunning) {
            yield \Amp\delay(30000);
            // Process queued notifications for users who came back online
        }
    }
}