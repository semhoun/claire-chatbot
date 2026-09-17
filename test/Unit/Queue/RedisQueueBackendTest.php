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

    public function testFailedMessageScanRetainsPayloadAndSkipsLiveJobs(): void
    {
        $id = $this->backend->dispatch('ExampleJob', ['submissionId' => 'submission'], 'telegram');
        $message = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertFalse($this->backend->isFailed($message));
        $this->backend->fail($message);
        self::assertTrue($this->backend->isFailed($message));
        $this->backend->dispatch('ExampleJob', ['live' => true], 'telegram');
        $cursor = '0';
        $found = [];
        do {
            foreach ($this->backend->failedMessages($cursor) as $failed) {
                $found[$failed->id] = $failed->payload;
            }
        } while ($cursor !== '0');
        self::assertSame([$id => ['submissionId' => 'submission']], $found);
        self::assertTrue($this->backend->isFailed($message));
    }

    public function testLogicalQueuesUseRawRedisWithoutSqlOutbox(): void
    {
        $sql = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(Settings::getAppRoot() . '/config/dependencies.php');
        $builder->addDefinitions([
            Settings::class => $this->settings,
            \Doctrine\DBAL\Connection::class => $sql,
        ]);
        $container = $builder->build();
        $dispatcher = $container->get(\App\Services\Queue\QueueDispatcherInterface::class);
        $backend = $container->get(\App\Services\Queue\QueueBackendInterface::class);
        self::assertSame([], $sql->createSchemaManager()->listTableNames());

        foreach ([
            [TelegramService::class, ['update_json' => '{"update_id":456}'], 'telegram'],
            [\App\Job\Telegram\StartThreadJob::class, [], 'telegram'],
            [\App\Job\Web\NewMessageJob::class, $this->webPayload(), 'default'],
            [\App\Job\Web\StartThreadJob::class,
                array_replace($this->webPayload(), ['threadId' => 'opening-test']), 'web'],
            ['ExampleJob', ['foo' => 'bar'], 'custom'],
        ] as [$jobClass, $payload, $queue]) {
            $id = $dispatcher->dispatch($jobClass, $payload, $queue);
            $queueKey = $this->prefix . 'queue:' . $queue;
            self::assertSame([$id], $this->redis->lRange($queueKey, 0, -1));
            $raw = $this->redis->hGetAll($this->jobKey($id));
            self::assertSame($queue, $raw['queue_name']);
            self::assertSame($jobClass, $raw['job_class']);
            self::assertArrayNotHasKey('outbox_id', $raw);
            if ($jobClass === \App\Job\Telegram\StartThreadJob::class) {
                $payload['generationId'] = $id;
            }
            self::assertSame($payload, json_decode($raw['payload'], true, flags: JSON_THROW_ON_ERROR));
            $message = $backend->reserveNextAvailable($queue, 0);
            self::assertNotNull($message);
            self::assertSame($id, $message->id);
            self::assertSame($queue, $message->queueName);
            self::assertArrayNotHasKey('outbox_id', $message->metadata);
            $backend->delete($message);
            self::assertSame(0, $this->redis->exists($this->jobKey($id)));
        }

        self::assertSame([], $this->redis->keys($this->prefix . 'queue:sql-outbox:*'));
        self::assertSame([], $sql->createSchemaManager()->listTableNames());
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
        return new RedisQueueBackend(new QueueRedisConnection($this->settings), $this->settings,
            \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
    }

    public function testWebDispatchAtomicallyQueuesAndRejectsBusyWithoutOverwrite(): void
    {
        $payload = $this->webPayload();
        $id = $this->backend->dispatch(\App\Job\Web\NewMessageJob::class, $payload, 'telegram');
        $key = $this->generationKey();
        $before = $this->redis->hGetAll($key);
        self::assertSame(['messageId' => 'msg-test', 'status' => 'queued', 'attempted' => '0',
            'jobId' => $id, 'queue' => 'telegram', 'jobMessageId' => 'msg-test'], $before);
        try {
            $payload['messageId'] = 'msg-other';
            $this->backend->dispatch(\App\Job\Web\NewMessageJob::class, $payload, 'telegram');
            self::fail('Busy dispatch must fail');
        } catch (\App\Services\ChatGenerationBusyException) {
            self::assertSame($before, $this->redis->hGetAll($key));
            self::assertSame([$id], $this->redis->lRange($this->key(), 0, -1));
            self::assertCount(1, $this->redis->keys($this->prefix . 'queue:job:*'));
        }
    }

    public function testWebSubmissionDeduplicationIsOwnerScopedAcrossThreadsAndQueues(): void
    {
        $payload = $this->webPayload();
        $id = $this->backend->dispatch(\App\Job\Web\NewMessageJob::class, $payload, 'telegram');
        $dedup = $this->redis->hGet($this->jobKey($id), 'deduplication_key');
        self::assertSame($id, $this->redis->get($dedup));
        self::assertSame(-1, $this->redis->ttl($dedup));
        foreach (['thread-test', 'other-thread'] as $thread) {
            try {
                $this->newBackend()->dispatch(\App\Job\Web\NewMessageJob::class,
                    array_replace($payload, ['messageId' => 'another-message', 'threadId' => $thread]), 'other-queue');
                self::fail('Duplicate submission must reject rather than return the previous job ID');
            } catch (\App\Services\ChatGenerationBusyException) {
                self::assertSame([$id], $this->redis->lRange($this->key(), 0, -1));
                self::assertSame(0, $this->redis->exists($this->prefix . 'queue:other-queue'));
                self::assertCount(1, $this->redis->keys($this->prefix . 'queue:job:*'));
                self::assertCount(1, $this->redis->keys($this->prefix . 'chat:generation:*'));
            }
        }
        $payload['session'][\App\Services\Auth::USERID] = 'another-owner';
        self::assertNotSame($id, $this->backend->dispatch(\App\Job\Web\NewMessageJob::class, $payload, 'telegram'));
        self::assertSame(2, $this->redis->lLen($this->key()));
    }

    public function testConcurrentWebSubmissionAcrossThreadsEnqueuesOnlyOnce(): void
    {
        $children = [];
        $signals = [];
        for ($index = 0; $index < 4; $index++) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($parent);
                fread($child, 1);
                $payload = array_replace($this->webPayload(), [
                    'threadId' => 'thread-' . $index, 'messageId' => 'message-' . $index,
                ]);
                try {
                    $this->newBackend()->dispatch(\App\Job\Web\NewMessageJob::class, $payload, 'telegram');
                    exit(0);
                } catch (\App\Services\ChatGenerationBusyException) {
                    exit(2);
                }
            }
            fclose($child);
            $signals[] = $parent;
            $children[] = $pid;
        }
        foreach ($signals as $signal) {
            fwrite($signal, '1');
            fclose($signal);
        }
        $results = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $results[] = pcntl_wexitstatus($status);
        }
        sort($results);
        self::assertSame([0, 2, 2, 2], $results);
        self::assertSame(1, $this->redis->lLen($this->key()));
        self::assertCount(1, $this->redis->keys($this->prefix . 'queue:job:*'));
        self::assertCount(1, $this->redis->keys($this->prefix . 'chat:generation:*'));
    }

    public function testWrongTypesNeverLeaveQueuedStateOrOrphanJob(): void
    {
        foreach ([$this->key(), $this->generationKey()] as $badKey) {
            $this->redis->set($badKey, 'wrong');
            try {
                $this->backend->dispatch(\App\Job\Web\NewMessageJob::class, $this->webPayload(), 'telegram');
                self::fail('Wrong type must fail');
            } catch (\RuntimeException) {
                self::assertSame([], $this->redis->keys($this->prefix . 'queue:job:*'));
                self::assertSame('wrong', $this->redis->get($badKey));
            }
            $this->redis->del($badKey);
        }
        self::assertSame(0, $this->redis->exists($this->generationKey()));
    }

    public function testOpeningDispatchAndDeletedThread(): void
    {
        $payload = $this->webPayload();
        unset($payload['messageId'], $payload['message']);
        $this->backend->dispatch(\App\Job\Web\StartThreadJob::class, $payload, 'telegram');
        self::assertSame('opening-thread-test', $this->redis->hGet($this->generationKey(), 'messageId'));
        $this->redis->hSet($this->generationKey(), 'status', 'deleted');
        $this->expectException(\App\Services\ChatGenerationBusyException::class);
        $this->backend->dispatch(\App\Job\Web\NewMessageJob::class, $this->webPayload(), 'telegram');
    }

    /** @return array<string, array{string}> */
    public static function sqlEngines(): array
    {
        return ['MariaDB' => ['MYSQL'], 'PostgreSQL' => ['PGSQL']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sqlEngines')]
    public function testSqlLockRejectsDispatchBeforeAnyRedisWrite(string $engine): void
    {
        $envPrefix = 'CLAIRE_CHAT_TEST_' . $engine;
        $dsn = getenv($envPrefix . '_DSN');
        if ($dsn === false || $dsn === '' || ! extension_loaded('pdo_' . strtolower($engine))) {
            self::markTestSkipped('Requires isolated ' . $envPrefix . '_DSN');
        }
        $user = getenv($envPrefix . '_USER') ?: '';
        $password = getenv($envPrefix . '_PASSWORD') ?: '';
        $owner = new \PDO($dsn, $user, $password);
        $contender = new \PDO($dsn, $user, $password);
        $other = $this->createStub(\Doctrine\DBAL\Connection::class);
        $other->method('getNativeConnection')->willReturn($contender);
        $payload = $this->webPayload();
        $payload['threadId'] = $this->prefix . 'thread';
        $stateKey = $this->prefix . 'chat:generation:'
            . hash('sha256', json_encode(['user-test', $payload['threadId']], JSON_THROW_ON_ERROR));
        $lock = new \App\Services\ChatThreadLock($owner, 'user-test', $payload['threadId']);
        $backend = new RedisQueueBackend(new QueueRedisConnection($this->settings), $this->settings, $other);
        try {
            $backend->dispatch(\App\Job\Web\NewMessageJob::class, $payload, 'telegram');
            self::fail('SQL lock must reject concurrent dispatch');
        } catch (\App\Services\ChatGenerationBusyException) {
            self::assertSame(0, $this->redis->exists($stateKey));
            self::assertSame([], $this->redis->keys($this->prefix . 'queue:job:*'));
        } finally {
            $lock->release();
        }
        $id = $backend->dispatch(\App\Job\Web\NewMessageJob::class, $payload, 'telegram');
        self::assertSame($id, $backend->reserveNextAvailable('telegram', 0)?->id);
    }

    public function testContentionDefersWithoutAttemptAndPermanentFailureKeepsPayloadOnce(): void
    {
        $id = $this->backend->dispatch('ExampleJob', ['recover' => 'me'], 'telegram');
        for ($contention = 0; $contention < 8; $contention++) {
            $job = $this->backend->reserveNextAvailable('telegram', 0);
            self::assertNotNull($job);
            $this->backend->defer($job);
            self::assertSame('0', $this->redis->hGet($this->jobKey($id), 'attempts'));
            $this->makeRetryReady($id);
        }
        $job = $this->backend->reserveNextAvailable('telegram', 0);
        $this->backend->fail($job);
        $this->backend->fail($job);
        self::assertSame('1', $this->redis->hGet($this->jobKey($id), 'attempts'));
        self::assertSame('dead', $this->redis->hGet($this->jobKey($id), 'state'));
        self::assertSame('{"recover":"me"}', $this->redis->hGet($this->jobKey($id), 'payload'));
        self::assertSame(1, $this->redis->zCard($this->key('dead')));
        self::assertNull($this->backend->reserveNextAvailable('telegram', 0));
    }

    private function webPayload(): array
    {
        return ['threadId' => 'thread-test', 'sessionId' => 'session-test', 'messageId' => 'msg-test',
            'submissionId' => 'submission-test', 'message' => 'hello',
            'session' => [\App\Services\Auth::USERID => 'user-test']];
    }

    public function testTelegramDeliveryRetryReusesDurableResponseAndThread(): void
    {
        [$generation, $state] = $this->telegramGeneration();
        $calls = 0;
        $deliveries = 0;
        $generate = function (string $thread) use (&$calls, $state): string {
            $calls++;
            self::assertSame('thread-test', $thread);
            self::assertTrue($state->snapshot('user-test', $thread)['responding']);
            self::assertSame('1', $state->get('user-test', $thread)['attempted']);
            // A second SQL connection cannot mutate this thread while the agent runs.
            try {
                new \App\Services\ChatThreadLock(new \PDO('sqlite::memory:'), 'user-test', $thread);
                self::fail('Agent must hold thread lock');
            } catch (\App\Services\ChatGenerationBusyException) {
            }
            return 'durable answer';
        };
        $deliver = function (string $answer) use (&$deliveries, $state): void {
            self::assertSame('durable answer', $answer);
            self::assertFalse($state->snapshot('user-test', 'thread-test')['responding']);
            if (++$deliveries === 1) {
                throw new \RuntimeException('Telegram unavailable');
            }
        };
        try {
            $generation->run('user-test', 'thread-test', 'update:42', $generate, $deliver);
            self::fail('Delivery failure must propagate');
        } catch (\RuntimeException $error) {
            self::assertSame('Telegram unavailable', $error->getMessage());
        }
        [$restarted] = $this->telegramGeneration();
        $restarted->run('user-test', 'changed-session-thread', 'update:42', $generate, $deliver);
        $restarted->run('user-test', 'changed-session-thread', 'update:42', $generate, $deliver);
        self::assertSame(1, $calls);
        self::assertSame(2, $deliveries);
    }

    public function testTelegramAmbiguousAttemptIsNonRetryableAndWebContentionDoesNotAttempt(): void
    {
        [$generation, $state] = $this->telegramGeneration();
        $state->set('user-test', 'thread-test', 'web-message', 'queued', false);
        $calls = 0;
        $generate = static function (string $threadId) use (&$calls): string {
            $calls++;
            self::assertSame('thread-test', $threadId);
            throw new \RuntimeException('provider failed after tool');
        };
        $deliver = static function (): void { self::fail('Must not deliver'); };
        try {
            $generation->run('user-test', 'thread-test', 'update:43', $generate, $deliver);
            self::fail('Web queued state must block Telegram');
        } catch (\App\Services\ChatGenerationBusyException) {
            self::assertSame(0, $calls);
            $record = $this->telegramJournal()->load(\App\Services\TelegramJournal::id('test-bot', 'update:43'));
            self::assertFalse($record['attempted']);
        }
        $state->set('user-test', 'thread-test', 'web-message', 'done', true);
        try {
            $generation->run('user-test', 'new-session-thread', 'update:43', $generate, $deliver);
            self::fail('Unsafe attempt must fail');
        } catch (\App\Services\Queue\NonRetryableJobException) {
            self::assertSame(1, $calls);
        }
        $generation->run('user-test', 'new-session-thread', 'update:43', $generate, $deliver);
        self::assertSame(1, $calls);
        self::assertSame('error', $state->get('user-test', 'thread-test')['status']);
    }

    public function testTelegramJournalIsBotScopedButSurvivesTokenRotation(): void
    {
        $calls = 0;
        foreach (['100:secret', '100:rotated', '200:secret'] as $token) {
            $this->settings = new Settings([
                'redis' => $this->settings->get('redis'), 'telegram' => ['bot_token' => $token],
            ]);
            [$generation] = $this->telegramGeneration();
            $generation->run('user-test', 'thread-test', 'update:99',
                static function () use (&$calls): string { $calls++; return 'answer'; },
                static function (string $text): void { self::assertSame('answer', $text); },
            );
        }
        self::assertSame(2, $calls);
    }

    public function testTelegramDeliveryCheckpointsAndUncertaintySurviveRestart(): void
    {
        [$generation] = $this->telegramGeneration();
        $generated = $textSends = $voiceSends = 0;
        $generate = static function () use (&$generated): string { $generated++; return 'answer'; };
        $deliver = static function (string $text, callable $checkpoint) use (&$textSends, &$voiceSends): void {
            self::assertSame('answer', $text);
            $checkpoint('text:0', static function () use (&$textSends): void { $textSends++; });
            $checkpoint('voice:0', static function () use (&$voiceSends): void {
                if (++$voiceSends === 1) {
                    throw new \RuntimeException('Ambiguous Telegram timeout');
                }
            });
        };
        try {
            $generation->run('user-test', 'thread-test', 'update:101', $generate, $deliver);
            self::fail('Delivery error must propagate');
        } catch (\RuntimeException) {
            $key = \App\Services\TelegramJournal::id('test-bot', 'update:101');
            $record = $this->telegramJournal()->load($key);
            self::assertSame('confirmed', $record['deliveries']['text:0']['status']);
            self::assertSame('uncertain', $record['deliveries']['voice:0']['status']);
            self::assertStringContainsString('Ambiguous Telegram timeout', $record['deliveries']['voice:0']['lastError']);
        }
        [$restarted] = $this->telegramGeneration();
        $restarted->run('user-test', 'new-thread', 'update:101', $generate, $deliver);
        self::assertSame(1, $generated);
        self::assertSame(1, $textSends);
        self::assertSame(2, $voiceSends);
        $record = $this->telegramJournal()->load($key);
        self::assertSame('thread-test', $record['threadId']);
        self::assertTrue($record['delivered']);
        self::assertSame('confirmed', $record['deliveries']['voice:0']['status']);
        self::assertSame(2, $record['deliveries']['voice:0']['attempts']);
        self::assertArrayHasKey('lastError', $record['deliveries']['voice:0']);
    }

    private ?\Doctrine\DBAL\Connection $telegramConnection = null;

    private function telegramJournal(): \App\Services\TelegramJournal
    {
        if ($this->telegramConnection === null) {
            $this->telegramConnection = \Doctrine\DBAL\DriverManager::getConnection([
                'driver' => 'pdo_sqlite', 'memory' => true,
            ]);
            require_once Settings::getAppRoot() . '/test/Support/TelegramSqlSchema.php';
            \App\Test\Support\TelegramSqlSchema::create($this->telegramConnection);
            require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
            \App\Test\Support\ChatTurnSqlSchema::create($this->telegramConnection);
        }
        return new \App\Services\TelegramJournal($this->telegramConnection);
    }

    private function telegramGeneration(): array
    {
        $redis = new \App\Services\RedisClient();
        $redis->connect('127.0.0.1', (int) getenv('QUEUE_TEST_REDIS_PORT'), 2);
        $state = new \App\Services\ChatGenerationState($redis, $this->settings);
        $this->telegramJournal();
        return [new \App\Services\TelegramGeneration(
            $this->settings, $this->telegramConnection, $state,
        ), $state];
    }

    private function generationKey(): string
    {
        return $this->prefix . 'chat:generation:'
            . hash('sha256', json_encode(['user-test', 'thread-test'], JSON_THROW_ON_ERROR));
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
