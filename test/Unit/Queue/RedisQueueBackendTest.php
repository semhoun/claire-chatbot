<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Queue\QueueMessage;
use App\Queue\QueueSerializer;
use App\Queue\RedisQueueBackend;
use App\Services\RedisClientInterface;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class RedisQueueBackendTest extends TestCase
{
    public function testDispatchStoresRedisJob(): void
    {
        $client = new InMemoryRedisClient();
        $backend = $this->createBackend($client);

        $jobId = $backend->dispatch('App\\Queue\\ExampleJob', ['foo' => 'bar'], 'telegram');

        $job = $client->hashes['claire:queue:job:' . $jobId] ?? null;

        $this->assertIsString($jobId);
        $this->assertSame('App\Queue\ExampleJob', $job['job_class'] ?? null);
        $this->assertSame('telegram', $job['queue_name'] ?? null);
    }

    public function testReserveReturnsHydratedQueueMessage(): void
    {
        $client = new InMemoryRedisClient();
        $backend = $this->createBackend($client);
        $jobId = $backend->dispatch('App\\Queue\\ExampleJob', ['foo' => 'bar'], 'telegram');

        $message = $backend->reserveNextAvailable('telegram');

        $this->assertInstanceOf(QueueMessage::class, $message);
        $this->assertSame($jobId, $message->id);
        $this->assertSame(['foo' => 'bar'], $message->payload);
    }

    private function createBackend(InMemoryRedisClient $client): RedisQueueBackend
    {
        return new RedisQueueBackend(
            $client,
            new QueueSerializer(),
            new Settings([
                'queue' => [
                    'defaultQueue' => 'default',
                    'default' => 'redis',
                ],
                'redis' => [
                    'prefix' => 'claire:',
                ],
            ]),
        );
    }
}

final class InMemoryRedisClient implements RedisClientInterface
{
    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    /** @var array<string, array<string, int>> */
    public array $sortedSets = [];

    public function eval(string $script, array $arguments, int $keyCount): mixed
    {
        if (str_contains($script, 'ZRANGEBYSCORE')) {
            $pendingKey = $arguments[0];

            if (! isset($this->sortedSets[$pendingKey]) || $this->sortedSets[$pendingKey] === []) {
                return false;
            }

            asort($this->sortedSets[$pendingKey]);
            $jobId = (string) array_key_first($this->sortedSets[$pendingKey]);
            unset($this->sortedSets[$pendingKey][$jobId]);

            return $jobId;
        }

        return true;
    }

    public function zadd(string $key, array $membersAndScores): int|false
    {
        foreach ($membersAndScores as $member => $score) {
            $this->sortedSets[$key][(string) $member] = (int) $score;
        }

        return count($membersAndScores);
    }

    public function hset(string $key, array $hash): int|false
    {
        $this->hashes[$key] = array_map(static fn (mixed $value): string => (string) $value, $hash);

        return 1;
    }

    public function hgetall(string $key): array|false
    {
        return $this->hashes[$key] ?? false;
    }

    public function del(array|string $keys): int|false
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $count = 0;

        foreach ($keys as $key) {
            if (isset($this->hashes[$key])) {
                unset($this->hashes[$key]);
                $count++;
            }
        }

        return $count;
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
    }

    public function connect(string $host, int $port, float $timeout): bool
    {
        return true;
    }

    public function auth(string $password): bool
    {
        return true;
    }

    public function select(int $database): bool
    {
        return true;
    }

    public function publish(string $channel, string $message): int|false
    {
        return 1;
    }
}
