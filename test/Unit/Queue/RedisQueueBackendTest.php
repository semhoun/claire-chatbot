<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Queue\QueueRedisConnection;
use App\Services\Queue\RedisQueueBackend;
use App\Services\Settings;
use App\Services\TelegramService;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

/** Run against a disposable Redis with QUEUE_TEST_REDIS_PORT set. */
final class RedisQueueBackendTest extends TestCase
{
    private Redis $redis;

    private RedisQueueBackend $backend;

    private Settings $settings;

    private string $prefix;

    protected function setUp(): void
    {
        $port = getenv('QUEUE_TEST_REDIS_PORT');
        if (! extension_loaded('redis') || $port === false) {
            self::markTestSkipped('Requires ext-redis and isolated QUEUE_TEST_REDIS_PORT');
        }
        $this->prefix = 'queue-test:' . bin2hex(random_bytes(8)) . ':';
        $this->settings = new Settings([
            'redis' => [
                'host' => '127.0.0.1', 'port' => (int) $port, 'timeout' => 2.0,
                'database' => 0, 'password' => null, 'prefix' => $this->prefix,
            ],
            'queue' => ['leaseSeconds' => 1, 'maxAttempts' => 3, 'retryDelaySeconds' => 2],
            'telegram' => ['bot_token' => 'test-bot'],
        ]);
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', (int) $port);
        $this->backend = $this->newBackend();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = $this->redis->keys($this->prefix . '*');
            if ($keys !== []) {
                $this->redis->del($keys);
            }
            $this->redis->close();
        }
    }

    public function testReservationIsDurableAndOnlyAcknowledgementDeletesPayload(): void
    {
        $id = $this->backend->dispatch('ExampleJob', ['foo' => 'bar'], 'telegram');
        self::assertSame(-1, $this->redis->ttl($this->jobKey($id)));
        $message = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($message);
        self::assertSame($id, $message->id);
        self::assertSame(['foo' => 'bar'], $message->payload);
        self::assertSame('1', $message->metadata['attempts']);
        self::assertSame('leased', $this->redis->hGet($this->jobKey($id), 'state'));
        self::assertNotFalse($this->redis->zScore($this->key('leased'), $id));
        self::assertNull($this->newBackend()->reserveNextAvailable('telegram', 0));
        $this->backend->delete($message);
        self::assertSame(0, $this->redis->exists($this->jobKey($id)));
        self::assertSame(0, $this->redis->zCard($this->key('leased')));
    }

    public function testCrashRecoveryBackoffAndStaleOwnerFencing(): void
    {
        $id = $this->backend->dispatch('ExampleJob', [], 'telegram');
        $old = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($old);
        $this->redis->zAdd($this->key('leased'), 0, $id);
        $restarted = $this->newBackend();
        self::assertNull($restarted->reserveNextAvailable('telegram', 0));
        self::assertSame('delayed', $this->redis->hGet($this->jobKey($id), 'state'));
        $this->makeRetryReady($id);
        $new = $restarted->reserveNextAvailable('telegram', 0);
        self::assertNotNull($new);
        self::assertSame($id, $new->id);
        self::assertNotSame($old->metadata['token'], $new->metadata['token']);
        self::assertSame('2', $new->metadata['attempts']);
        self::assertFalse($this->backend->renew($old));
        $this->backend->release($old);
        self::assertSame('leased', $this->redis->hGet($this->jobKey($id), 'state'));
        try {
            $this->backend->delete($old);
            self::fail('Stale acknowledgement must fail');
        } catch (RuntimeException) {
            self::assertSame(1, $this->redis->exists($this->jobKey($id)));
        }
        $restarted->delete($new);
    }

    public function testRetriesAreBoundedWithExponentialBackoffAndDeadLetter(): void
    {
        $id = $this->backend->dispatch('ExampleJob', [], 'telegram');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $message = $this->backend->reserveNextAvailable('telegram', 0);
            self::assertNotNull($message);
            $before = microtime(true);
            $this->backend->release($message);
            // Releasing twice must not create a second retry.
            $this->backend->release($message);
            self::assertNull($this->backend->reserveNextAvailable('telegram', 0));
            if ($attempt < 3) {
                $due = $this->redis->zScore($this->key('delayed'), $id);
                self::assertGreaterThanOrEqual($before + 2 ** $attempt - 0.1, $due);
                self::assertSame(1, $this->redis->zCard($this->key('delayed')));
                $this->makeRetryReady($id);
            }
        }
        self::assertSame('dead', $this->redis->hGet($this->jobKey($id), 'state'));
        self::assertSame(1, $this->redis->zCard($this->key('dead')));
        self::assertSame(0, $this->redis->zCard($this->key('delayed')));
        self::assertSame(-1, $this->redis->ttl($this->jobKey($id)));
    }

    public function testRepeatedCrashesAlsoReachDeadLetter(): void
    {
        $id = $this->backend->dispatch('ExampleJob', [], 'telegram');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertNotNull($this->backend->reserveNextAvailable('telegram', 0));
            $this->redis->zAdd($this->key('leased'), 0, $id);
            self::assertNull($this->newBackend()->reserveNextAvailable('telegram', 0));
            if ($attempt < 3) {
                $this->makeRetryReady($id);
            }
        }
        self::assertSame('dead', $this->redis->hGet($this->jobKey($id), 'state'));
    }

    public function testTelegramDeduplicationSurvivesReservationAndAcknowledgement(): void
    {
        $payload = ['update_json' => '{"update_id":123}'];
        $id = $this->backend->dispatch(TelegramService::class, $payload, 'telegram');
        self::assertSame($id, $this->newBackend()->dispatch(TelegramService::class, $payload, 'telegram'));
        self::assertSame(1, $this->redis->lLen($this->key()));
        $message = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($message);
        self::assertSame(-1, $this->redis->ttl($message->metadata['deduplication_key']));
        self::assertSame($id, $this->backend->dispatch(TelegramService::class, $payload, 'telegram'));
        $this->backend->delete($message);
        self::assertGreaterThan(0, $this->redis->ttl($message->metadata['deduplication_key']));
        self::assertSame($id, $this->newBackend()->dispatch(TelegramService::class, $payload, 'telegram'));
        self::assertNull($this->backend->reserveNextAvailable('telegram', 0));
        self::assertNotSame($id, $this->backend->dispatch(
            TelegramService::class, ['update_json' => '{"update_id":124}'], 'telegram',
        ));
    }

    public function testConcurrentTelegramDispatchEnqueuesOnlyOnce(): void
    {
        $children = [];
        for ($index = 0; $index < 4; $index++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                $this->newBackend()->dispatch(TelegramService::class, ['update_json' => '{"update_id":99}'], 'telegram');
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame(1, $this->redis->lLen($this->key()));
    }

    public function testLongJobRenewsLeaseWhileOperationBlocks(): void
    {
        $id = $this->backend->dispatch('ExampleJob', [], 'telegram');
        $message = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($message);
        $this->backend->withLease($message, function (): void {
            usleep(2_200_000);
            self::assertNull($this->newBackend()->reserveNextAvailable('telegram', 0));
        });
        self::assertSame('1', $this->redis->hGet($this->jobKey($id), 'attempts'));
        $this->backend->delete($message);
    }

    public function testLostHeartbeatDoesNotRunInheritedShutdownHooks(): void
    {
        $id = $this->backend->dispatch('ExampleJob', [], 'telegram');
        $message = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($message);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            $workerPid = getmypid();
            register_shutdown_function(static function () use ($workerPid, $sockets): void {
                if (getmypid() !== $workerPid) {
                    fwrite($sockets[1], 'inherited shutdown');
                }
            });
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_alarm(5);
            try {
                $this->newBackend()->withLease($message, function () use ($id): void {
                    $connection = new QueueRedisConnection($this->settings);
                    $connection->evaluate("return redis.call('HSET', KEYS[1], 'token', 'superseded')", [
                        $this->jobKey($id),
                    ], 1);
                    usleep(800_000);
                });
            } catch (RuntimeException) {
                fwrite($sockets[1], 'reservation lost');
            }
            posix_kill(getmypid(), SIGKILL);
            exit(1);
        }
        fclose($sockets[1]);
        pcntl_waitpid($pid, $status);
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        self::assertSame('reservation lost', stream_get_contents($sockets[0]));
        fclose($sockets[0]);
    }

    public function testQuickJobsCleanUpHeartbeatWithInheritedTerminationHandler(): void
    {
        $this->backend->dispatch('ExampleJob', [], 'telegram');
        $message = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($message);
        $previous = pcntl_signal_get_handler(SIGTERM);
        pcntl_signal(SIGTERM, static function (): void {});
        try {
            for ($index = 0; $index < 20; $index++) {
                $this->backend->withLease($message, static function (): void {});
            }
        } finally {
            pcntl_signal(SIGTERM, $previous);
        }
        $this->backend->delete($message);
        self::assertSame(0, $this->redis->exists($this->jobKey($message->id)));
    }

    public function testKilledWorkerStopsRenewalAndJobIsRecovered(): void
    {
        $id = $this->backend->dispatch('ExampleJob', [], 'telegram');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $backend = $this->newBackend();
            $message = $backend->reserveNextAvailable('telegram', 0);
            $backend->withLease($message, static function (): void {
                posix_kill(getmypid(), SIGKILL);
            });
            exit(1);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        usleep(1_400_000);
        self::assertNull($this->newBackend()->reserveNextAvailable('telegram', 0));
        self::assertSame('delayed', $this->redis->hGet($this->jobKey($id), 'state'));
        $this->makeRetryReady($id);
        $message = $this->newBackend()->reserveNextAvailable('telegram', 0);
        self::assertNotNull($message);
        self::assertSame($id, $message->id);
        self::assertSame('2', $message->metadata['attempts']);
    }

    public function testLegacyPayloadLosesExpiryOnReservation(): void
    {
        $this->redis->hMSet($this->jobKey('legacy'), [
            'id' => 'legacy', 'job_class' => 'ExampleJob', 'payload' => '{}', 'queue_name' => 'telegram',
        ]);
        $this->redis->expire($this->jobKey('legacy'), 10);
        $this->redis->lPush($this->key(), 'legacy');
        $message = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($message);
        self::assertSame('legacy', $message->id);
        self::assertSame(-1, $this->redis->ttl($this->jobKey('legacy')));
    }

    public function testMalformedPayloadEventuallyReachesDeadLetter(): void
    {
        $id = $this->backend->dispatch('ExampleJob', [], 'telegram');
        $this->redis->hSet($this->jobKey($id), 'payload', 'not-json');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $this->backend->reserveNextAvailable('telegram', 0);
                self::fail('Invalid payload must fail hydration');
            } catch (RuntimeException) {
                self::assertSame('leased', $this->redis->hGet($this->jobKey($id), 'state'));
            }
            $this->redis->zAdd($this->key('leased'), 0, $id);
            self::assertNull($this->backend->reserveNextAvailable('telegram', 0));
            if ($attempt < 3) {
                $this->makeRetryReady($id);
            }
        }
        self::assertSame('dead', $this->redis->hGet($this->jobKey($id), 'state'));
    }

    public function testFailedEnqueueDoesNotMarkTelegramUpdateAsAccepted(): void
    {
        $this->redis->set($this->key(), 'wrong-type');
        $payload = ['update_json' => '{"update_id":12}'];
        try {
            $this->backend->dispatch(TelegramService::class, $payload, 'telegram');
            self::fail('Enqueue must fail');
        } catch (RuntimeException) {
            self::assertSame([], $this->redis->keys($this->prefix . 'telegram:update:*'));
            self::assertSame([], $this->redis->keys($this->prefix . 'queue:job:*'));
        }
        $this->redis->del($this->key());
        $id = $this->backend->dispatch(TelegramService::class, $payload, 'telegram');
        self::assertSame($id, $this->backend->reserveNextAvailable('telegram', 0)?->id);
    }

    private function newBackend(): RedisQueueBackend
    {
        return new RedisQueueBackend(new QueueRedisConnection($this->settings), $this->settings);
    }

    private function key(string $suffix = ''): string
    {
        return $this->prefix . 'queue:telegram' . ($suffix === '' ? '' : ':' . $suffix);
    }

    private function jobKey(string $id): string
    {
        return $this->prefix . 'queue:job:' . $id;
    }

    private function makeRetryReady(string $id): void
    {
        $this->redis->zAdd($this->key('delayed'), 0, $id);
    }
}
