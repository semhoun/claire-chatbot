<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class RedisClient implements RedisClientInterface
{
    private readonly \Redis $redis;

    private string $host = '127.0.0.1';

    private int $port = 6379;

    private float $timeout = 2.0;

    private ?string $password = null;

    private int $database = 0;

    public function __construct()
    {
        if (! extension_loaded('redis')) {
            throw new RuntimeException('The ext-redis extension is required for the Redis queue backend');
        }

        $this->redis = new \Redis();
    }

    public function publish(string $channel, string $message): int|false
    {
        return $this->redis->publish($channel, $message);
    }

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
    ): void {
        $this->setReadTimeout($heartbeatSeconds);

        while ($shouldContinue()) {
            try {
                $this->redis->subscribe($channels, static function ($redis, string $channel, string $message) use ($callback, $shouldContinue): void {
                    $callback($channel, $message);

                    if (! $shouldContinue()) {
                        $redis->unsubscribe();
                    }
                });

                return;
            } catch (\RedisException $exception) {
                if (! $shouldContinue() || ! str_contains(strtolower($exception->getMessage()), 'read error')) {
                    throw $exception;
                }

                $this->reconnect();
                $this->setReadTimeout($heartbeatSeconds);
            }
        }
    }

    /** @param array<string, scalar|null> $hash */
    public function hset(string $key, array $hash): int|false
    {
        $result = $this->redis->hMSet($key, $hash);

        return $result ? 1 : false;
    }

    /** @return array<string, string>|false */
    public function hgetall(string $key): array|false
    {
        return $this->redis->hGetAll($key);
    }

    public function del(array|string $keys): int|false
    {
        return $this->redis->del($keys);
    }

    public function connect(string $host, int $port, float $timeout): bool
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;

        return $this->redis->connect($host, $port, $timeout);
    }

    public function auth(string $password): bool
    {
        $this->password = $password;

        return $this->redis->auth($password);
    }

    public function select(int $database): bool
    {
        $this->database = $database;

        return $this->redis->select($database);
    }

    public function close(): bool
    {
        return $this->redis->close();
    }

    public function setReadTimeout(float $timeout): bool
    {
        return $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, (string) $timeout);
    }

    public function reconnect(): bool
    {
        $this->close();

        $connected = $this->redis->connect($this->host, $this->port, $this->timeout);

        if (! $connected) {
            return false;
        }

        if ($this->password !== null && $this->password !== '') {
            $this->redis->auth($this->password);
        }

        $this->redis->select($this->database);

        return true;
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array{0: string, 1: string}|null
     */
    public function brpop(array $keys, float|int $timeout): ?array
    {
        try {
            $result = $this->redis->brPop($keys, (int) $timeout);
        } catch (\RedisException $e) {
            // Timeout reached - Redis closes connection, this is normal
            if (str_contains(strtolower($e->getMessage()), 'read error')) {
                return null;
            }

            throw $e;
        }

        if ($result === false || ! is_array($result) || count($result) < 2) {
            return null;
        }

        return [(string) $result[0], (string) $result[1]];
    }

    /**
     * @param array<int, string> $values
     */
    public function lpush(string $key, array $values): int|false
    {
        return $this->redis->lPush($key, ...$values);
    }
}
