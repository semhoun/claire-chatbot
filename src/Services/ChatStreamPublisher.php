<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

readonly class ChatStreamPublisher
{
    public function __construct(
        private RedisClientInterface $redisClient,
        private ChatStreamSubscriber $chatStreamSubscriber,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function publish(string $chatId, string $event, array $payload): void
    {
        $message = json_encode([
            'version' => 1,
            'event' => $event,
            'chatId' => $chatId,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        $result = $this->redisClient->publish($this->chatStreamSubscriber->channel($chatId), $message);
        if ($result === false) {
            throw new RuntimeException('Unable to publish chat stream event');
        }
    }
}
