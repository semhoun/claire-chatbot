<?php

declare(strict_types=1);

namespace App\Services;

final readonly class ChatStreamSubscriber
{
    public function __construct(
        private RedisClient $redisClient,
        private Settings $settings,
    ) {
    }

    public function channel(string $threadId): string
    {
        return $this->settings->get('redis.prefix') . 'sse:chat:' . $threadId;
    }

    public function popMessage(string $threadId, int $timeout): ?string
    {
        $result = $this->redisClient->brpop([$this->channel($threadId) . ':queue'], $timeout);

        return $result !== null ? $result[1] : null;
    }
}
