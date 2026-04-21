<?php

declare(strict_types=1);

namespace App\Services;

final readonly class ChatStreamSubscriber
{
    private const float HEARTBEAT_SECONDS = 15.0;

    public function __construct(
        private RedisClient $redisClient,
        private Settings $settings,
    ) {
    }

    /**
     * @param callable(string): void $onMessage
     */
    public function subscribe(string $threadId, callable $onMessage): void
    {
        $this->redisClient->subscribeWithHeartbeat(
            [$this->channel($threadId)],
            static function (string $channel, string $message) use ($onMessage): void {
                $onMessage($message);
            },
            self::HEARTBEAT_SECONDS,
            static fn (): bool => ! connection_aborted(),
        );
    }

    public function channel(string $threadId): string
    {
        return $this->settings->get('redis.prefix') . 'sse:chat:' . $threadId;
    }
}
