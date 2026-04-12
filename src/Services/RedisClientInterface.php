<?php

declare(strict_types=1);

namespace App\Services;

interface RedisClientInterface
{
    public function publish(string $channel, string $message): int|false;

    /**
     * @param array<int, string> $channels
     * @param callable(string, string): void $callback
     * @param callable(): bool $shouldContinue
     */
    public function subscribeWithHeartbeat(
        array $channels,
        callable $callback,
        float $heartbeatSeconds,
        callable $shouldContinue,
    ): void;

    /** @param array<string, scalar|null> $hash */
    public function hset(string $key, array $hash): int|false;

    /** @return array<string, string>|false */
    public function hgetall(string $key): array|false;

    public function del(array|string $keys): int|false;

    public function connect(string $host, int $port, float $timeout): bool;

    public function auth(string $password): bool;

    public function select(int $database): bool;

    public function close(): bool;

    public function setReadTimeout(float $timeout): bool;

    public function reconnect(): bool;

    /**
     * @param array<int, string> $keys
     *
     * @return array{0: string, 1: string}|null
     */
    public function brpop(array $keys, float|int $timeout): ?array;

    /**
     * @param array<int, string> $values
     */
    public function lpush(string $key, array $values): int|false;
}
