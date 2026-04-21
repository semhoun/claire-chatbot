<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

readonly class ChatStreamPublisher
{
    public function __construct(
        private RedisClient $redisClient,
        private ChatStreamSubscriber $chatStreamSubscriber,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function publish(string $threadId, string $event, array $payload): void
    {
        $message = json_encode([
            'version' => 1,
            'event' => $event,
            'threadId' => $threadId,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        $result = $this->redisClient->publish($this->chatStreamSubscriber->channel($threadId), $message);
        if ($result === false) {
            throw new RuntimeException('Unable to publish chat stream event');
        }
    }
}
