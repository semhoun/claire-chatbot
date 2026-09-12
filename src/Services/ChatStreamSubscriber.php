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
        self::unScope($threadId);
        return $this->settings->get('redis.prefix') . 'sse:chat:' . $threadId;
    }

    /** Internal routing token, never accepted directly from an HTTP request. */
    public static function scope(string $userId, string $sessionId): string
    {
        if ($userId === '' || $sessionId === '') {
            throw new \InvalidArgumentException('Authenticated user and SSE session are required');
        }

        return 'user.' . bin2hex($userId) . '.session.' . bin2hex($sessionId);
    }

    public static function unScope(string $channel): string
    {
        if (preg_match('/^user\.([a-f0-9]+)\.session\.([a-f0-9]+)$/D', $channel, $matches) !== 1
            || strlen($matches[1]) % 2 !== 0 || strlen($matches[2]) % 2 !== 0) {
            throw new \InvalidArgumentException('Unscoped SSE channel is forbidden');
        }

        return hex2bin($matches[2]);
    }

    public function popMessage(string $threadId, int $timeout): ?string
    {
        $result = $this->redisClient->brpop([$this->channel($threadId) . ':queue'], $timeout);

        return $result !== null ? $result[1] : null;
    }
}
