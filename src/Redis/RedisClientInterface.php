<?php

declare(strict_types=1);

namespace App\Redis;

interface RedisClientInterface
{
    public function eval(string $script, array $arguments, int $keyCount): mixed;

    public function zadd(string $key, array $membersAndScores): int|false;

    public function hset(string $key, array $hash): int|false;

    public function hgetall(string $key): array|false;

    public function del(array|string $keys): int|false;

    public function expire(string $key, int $seconds): bool;

    public function connect(string $host, int $port, float $timeout): bool;

    public function auth(string $password): bool;

    public function select(int $database): bool;
}
