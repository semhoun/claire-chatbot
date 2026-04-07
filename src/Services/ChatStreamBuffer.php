<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

readonly class ChatStreamBuffer
{
    public function __construct(
        private RedisClientInterface $redisClient,
        private Settings $settings,
    ) {
    }

    public function push(string $chatId, string $message): void
    {
        $key = $this->streamKey($chatId);
        $script = <<<'LUA'
redis.call('RPUSH', KEYS[1], ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
return 1
LUA;

        $result = $this->redisClient->eval($script, [
            $key,
            $message,
            (string) $this->ttl(),
        ], 1);

        if ($result === false) {
            throw new RuntimeException('Unable to push chat event to Redis buffer');
        }
    }

    /**
     * @return array<int, string>
     */
    public function readSince(string $chatId, int $offset): array
    {
        $key = $this->streamKey($chatId);
        $script = <<<'LUA'
local key = KEYS[1]
local startIndex = tonumber(ARGV[1])
local items = redis.call('LRANGE', key, startIndex, -1)
redis.call('EXPIRE', key, ARGV[2])
return items
LUA;

        $result = $this->redisClient->eval($script, [
            $key,
            (string) max(0, $offset),
            (string) $this->ttl(),
        ], 1);

        if (! is_array($result)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $result));
    }

    public function length(string $chatId): int
    {
        $script = <<<'LUA'
local key = KEYS[1]
return redis.call('LLEN', key)
LUA;

        $result = $this->redisClient->eval($script, [
            $this->streamKey($chatId),
        ], 1);

        return (int) $result;
    }

    private function streamKey(string $chatId): string
    {
        return $this->settings->get('redis.prefix') . 'sse:buffer:' . $chatId;
    }

    private function ttl(): int
    {
        return 300;
    }
}
