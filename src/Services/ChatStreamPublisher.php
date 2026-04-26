<?php

declare(strict_types=1);

namespace App\Services;

readonly class ChatStreamPublisher
{
    public function __construct(
        private RedisClient $redisClient,
        private ChatStreamSubscriber $chatStreamSubscriber,
        private Settings $settings,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function publish(string $threadId, string $event, array $payload): void
    {
        $channel = $this->chatStreamSubscriber->channel($threadId);

        $message = json_encode([
            'version' => 1,
            'event' => $event,
            'threadId' => $threadId,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        // Push to a session-specific queue for reliable delivery (wait/notify pattern)
        $queueKey = $channel . ':queue';
        $this->redisClient->rpush($queueKey, [$message]);
        $this->redisClient->expire($queueKey, $this->settings->get('sse.queue_ttl')); // TTL from settings
    }
}
