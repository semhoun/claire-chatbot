<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

readonly class ChatStreamPublisher
{
    public function __construct(
        private RedisClientInterface $redisClient,
        private ChatStreamBuffer $chatStreamBuffer,
        private Settings $settings,
    ) {
    }

    public function publish(string $chatId, string $event, array $payload): void
    {
        $message = json_encode([
            'version' => 1,
            'event' => $event,
            'chatId' => $chatId,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        $this->chatStreamBuffer->push($chatId, $message);

        $result = $this->redisClient->publish($this->channel($chatId), $message);
        if ($result === false) {
            throw new RuntimeException('Unable to publish chat stream event');
        }
    }

    public function channel(string $chatId): string
    {
        return $this->settings->get('redis.prefix') . 'sse:chat:' . $chatId;
    }
}
