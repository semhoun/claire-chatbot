<?php

declare(strict_types=1);

namespace App\Redis;

use RuntimeException;

final class RedisRuntime
{
    public static function createClient(): object
    {
        if (! extension_loaded('redis')) {
            throw new RuntimeException('The ext-redis extension is required for the Redis queue backend');
        }

        $redisClass = 'Redis';
        /** @var object $client */
        $client = new $redisClass();

        return $client;
    }
}
