<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Services\Settings;
use Redis;
use RuntimeException;
use Throwable;

/** Lua and heartbeat traffic must not share other services' sockets. */
class QueueRedisConnection
{
    private ?Redis $redis = null;

    private int $pid = 0;

    public function __construct(private readonly Settings $settings)
    {
    }

    /** @param list<string|int> $arguments */
    public function evaluate(string $script, array $arguments, int $keyCount): mixed
    {
        try {
            if (! $this->redis instanceof \Redis || $this->pid !== getmypid()) {
                $redis = new Redis();
                if (! $redis->connect(
                    (string) $this->settings->get('redis.host'),
                    (int) $this->settings->get('redis.port'),
                    (float) $this->settings->get('redis.timeout'),
                )) {
                    throw new RuntimeException('Unable to connect to queue Redis');
                }

                $password = $this->settings->get('redis.password');
                if (is_string($password) && $password !== '' && ! $redis->auth($password)) {
                    throw new RuntimeException('Unable to authenticate queue Redis');
                }

                if (! $redis->select((int) $this->settings->get('redis.database'))) {
                    throw new RuntimeException('Unable to select queue Redis database');
                }

                $this->redis = $redis;
                $this->pid = getmypid();
            }

            $result = $this->redis->eval($script, $arguments, $keyCount);
            if ($result === false) {
                throw new RuntimeException('Redis queue operation failed');
            }

            return $result;
        } catch (Throwable $throwable) {
            $this->redis = null;
            throw $throwable;
        }
    }
}
