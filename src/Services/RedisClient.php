<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class RedisClient implements RedisClientInterface
{
    private object $client;

    public function __construct()
    {
        if (! extension_loaded('redis')) {
            throw new RuntimeException('The ext-redis extension is required for the Redis queue backend');
        }

        $this->client = new \Redis();
    }

    public function eval(string $script, array $arguments, int $keyCount): mixed
    {
        return $this->client->eval($script, $arguments, $keyCount);
    }

    public function zadd(string $key, array $membersAndScores): int|false
    {
        $arguments = [$key];

        foreach ($membersAndScores as $member => $score) {
            $arguments[] = $score;
            $arguments[] = $member;
        }

        /** @var int|false $result */
        $result = $this->client->zAdd(...$arguments);

        return $result;
    }

    public function hset(string $key, array $hash): int|false
    {
        $result = $this->client->hMSet($key, $hash);

        return $result ? 1 : false;
    }

    public function hgetall(string $key): array|false
    {
        return $this->client->hGetAll($key);
    }

    public function del(array|string $keys): int|false
    {
        return $this->client->del($keys);
    }

    public function expire(string $key, int $seconds): bool
    {
        return $this->client->expire($key, $seconds);
    }

    public function connect(string $host, int $port, float $timeout): bool
    {
        return $this->client->connect($host, $port, $timeout);
    }

    public function auth(string $password): bool
    {
        return $this->client->auth($password);
    }

    public function select(int $database): bool
    {
        return $this->client->select($database);
    }
}
