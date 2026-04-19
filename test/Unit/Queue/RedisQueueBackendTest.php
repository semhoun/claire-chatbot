<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Queue\QueueMessage;
use App\Services\Queue\QueueSerializer;
use App\Services\Queue\RedisQueueBackend;
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

    public function testDeleteRemovesJob(): void
    {
        $client = new InMemoryRedisClient();
        $backend = $this->createBackend($client);
        $jobId = $backend->dispatch('App\\Queue\\ExampleJob', ['foo' => 'bar'], 'telegram');

        $message = $backend->reserveNextAvailable('telegram');
        $this->assertNotNull($message);

        $backend->delete($message);

        $this->assertFalse(isset($client->hashes['claire:queue:job:' . $jobId]));
    }

    private function createBackend(InMemoryRedisClient $client): RedisQueueBackend
    {
        return new RedisQueueBackend(
            $client,
            new QueueSerializer(),
            new Settings([
                'queue' => [
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

    /** @var array<string, array<int, string>> */
    public array $lists = [];

    public function lpush(string $key, array $values): int|false
    {
        if (! isset($this->lists[$key])) {
            $this->lists[$key] = [];
        }

        foreach ($values as $value) {
            array_unshift($this->lists[$key], (string) $value);
        }

        return count($values);
    }

    /**
     * @param array<int, string> $keys
     * @return array{0: string, 1: string}|null
     */
    public function brpop(array $keys, float|int $timeout): ?array
    {
        foreach ($keys as $key) {
            if (isset($this->lists[$key]) && $this->lists[$key] !== []) {
                $jobId = array_pop($this->lists[$key]);

                if ($jobId !== null) {
                    return [$key, $jobId];
                }
            }
        }

        return null;
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

    public function subscribeWithHeartbeat(
        array $channels,
        callable $callback,
        float $heartbeatSeconds,
        callable $shouldContinue,
    ): void {
    }

    public function close(): bool
    {
        return true;
    }

    public function setReadTimeout(float $timeout): bool
    {
        return true;
    }

    public function reconnect(): bool
    {
        return true;
    }
}
