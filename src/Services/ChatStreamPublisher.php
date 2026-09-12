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
        // Audio forwarding receives the internal token as sessionId; do not expose it to clients.
        $payload['sessionId'] = ChatStreamSubscriber::unScope($threadId);

        $message = json_encode([
            'version' => 1,
            'event' => $event,
            'threadId' => $payload['threadId'] ?? '',
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        // Push to a session-specific queue for reliable delivery (wait/notify pattern)
        $queueKey = $channel . ':queue';
        if ($this->redisClient->lpush($queueKey, [$message]) === false) {
            throw new \RuntimeException('Cannot publish chat stream event');
        }

        // BRPOP may consume the last event and remove the key before EXPIRE returns false.
        $this->redisClient->expire($queueKey, $this->settings->get('sse.queue_ttl'));
    }

    public function generationState(): ChatGenerationState
    {
        return new ChatGenerationState($this->redisClient, $this->settings);
    }
}
