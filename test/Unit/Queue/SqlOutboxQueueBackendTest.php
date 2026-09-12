<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Queue\QueueRedisConnection;
use App\Services\Queue\RedisQueueBackend;
use App\Services\Queue\SqlOutboxQueueBackend;
use App\Services\Queue\SqlQueueOutbox;
use App\Services\Settings;
use App\Services\TelegramService;
use App\Services\Queue\NonRetryableJobException;
use App\Test\Support\TelegramSqlSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

require_once Settings::getAppRoot() . '/test/Support/TelegramSqlSchema.php';

/** Requires an explicitly selected disposable Redis; only random-prefix keys are removed. */
final class SqlOutboxQueueBackendTest extends TestCase
{
    private \Redis $redis;
    private Connection $sql;
    private RedisQueueBackend $raw;
    private SqlOutboxQueueBackend $backend;
    private SqlQueueOutbox $outbox;
    private string $prefix;

    protected function setUp(): void
    {
        $port = getenv('OUTBOX_TEST_REDIS_PORT');
        if ($port === false || ! extension_loaded('redis')) {
            self::markTestSkipped('Requires ext-redis and isolated OUTBOX_TEST_REDIS_PORT');
        }
        $this->prefix = 'outbox-test:' . bin2hex(random_bytes(12)) . ':';
        $settings = new Settings([
            'telegram' => ['bot_token' => 'outbox-bot-' . bin2hex(random_bytes(12)) . ':test-secret'],
            'queue' => [],
            'redis' => ['host' => '127.0.0.1', 'port' => (int) $port, 'prefix' => $this->prefix,
                'password' => null, 'database' => 0, 'timeout' => 1],
        ]);
        $this->sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        TelegramSqlSchema::create($this->sql);
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', (int) $port, 1);
        $this->raw = new RedisQueueBackend(new QueueRedisConnection($settings), $settings, $this->sql);
        $this->outbox = new SqlQueueOutbox($this->sql, $settings);
        $this->backend = new SqlOutboxQueueBackend($this->raw, $this->outbox);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = $this->redis->keys($this->prefix . '*');
            if ($keys !== []) {
                $this->redis->del($keys);
            }
            $this->redis->close();
            $this->sql->close();
        }
    }

    public function testRepeatedPublicationPreservesClaimTokenAndNeverPushesDuplicate(): void
    {
        $id = $this->backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
        $job = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($job);
        self::assertSame($id, $job->metadata['outbox_id']);
        $before = $this->redis->hGetAll($this->key($id));
        $this->raw->dispatchWithId($id, TelegramService::class, ['wrong' => true], 'telegram');
        self::assertSame($before, $this->redis->hGetAll($this->key($id)));
        self::assertSame(0, $this->redis->lLen($this->prefix . 'queue:sql-outbox:telegram'));
        $this->backend->withLease($job, static function (): void {});
        self::assertSame('completed', $this->sql->fetchOne('SELECT status FROM queue_outbox WHERE id = ?', [$id]));
        $this->backend->delete($job);
        self::assertSame(0, $this->redis->exists($this->key($id)));
    }

    public function testRedisLossRecoversPublishedReceiptBeforeReserve(): void
    {
        $id = $this->backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
        $this->redis->del($this->key($id), $this->prefix . 'queue:sql-outbox:telegram');
        $this->sql->update('queue_outbox', ['available_at' => 0], ['id' => $id]);
        $job = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($job);
        self::assertSame($id, $job->id);
        self::assertSame($this->payload(), $job->payload);
    }

    public function testClassicWorkerCannotReserveSqlOutboxJob(): void
    {
        $id = $this->backend->dispatch(TelegramService::class, $this->payload(), 'default');
        self::assertNull($this->raw->reserveNextAvailable('default', 0));
        self::assertSame(0, $this->redis->exists($this->prefix . 'queue:default'));
        self::assertSame(1, $this->redis->lLen($this->prefix . 'queue:sql-outbox:default'));
        self::assertSame('default', $this->sql->fetchOne('SELECT queue_name FROM queue_outbox WHERE id = ?', [$id]));
        $job = $this->backend->reserveNextAvailable('default', 0);
        self::assertNotNull($job);
        self::assertSame($id, $job->id);
        self::assertSame('sql-outbox:default', $job->queueName);
        $this->backend->withLease($job, static function (): void {});
        $this->backend->delete($job);
        self::assertSame(0, $this->redis->exists($this->key($id)));
        self::assertSame(0, $this->redis->zCard($this->prefix . 'queue:sql-outbox:default:leased'));
    }

    public function testReservationAlternatesSqlAndClassicWebQueues(): void
    {
        $sqlId = $this->backend->dispatch(TelegramService::class, $this->payload(), 'default');
        $webId = $this->backend->dispatch('WebJob', [], 'default');
        self::assertSame($sqlId, $this->backend->reserveNextAvailable('default', 0)?->id);
        $this->backend->dispatch(TelegramService::class, ['update_json' => '{"update_id":43}'], 'default');
        $web = $this->backend->reserveNextAvailable('default', 0);
        self::assertNotNull($web);
        self::assertSame($webId, $web->id);
        self::assertSame('default', $web->queueName);
    }

    public function testSqlArrivalDoesNotWaitForClassicQueueTimeout(): void
    {
        $id = $this->outbox->enqueue(TelegramService::class, $this->payload(), 'default');
        $this->sql->update('queue_outbox', ['available_at' => time() + 60], ['id' => $id]);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                usleep(200_000);
                $this->raw->dispatchWithId($id, TelegramService::class, $this->payload(), 'default');
            } finally {
                // The publisher child only uses Redis; never destruct inherited SQL connections.
                posix_kill(getmypid(), SIGKILL);
                exit(1);
            }
        }
        try {
            $started = microtime(true);
            $job = $this->backend->reserveNextAvailable('default', 3);
            self::assertLessThan(2.0, microtime(true) - $started);
            self::assertNotNull($job);
            self::assertSame($id, $job->id);
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    public function testEmptyQueuesShareOneTimeoutBudget(): void
    {
        $started = microtime(true);
        self::assertNull($this->backend->reserveNextAvailable('default', 1));
        $elapsed = microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.9, $elapsed);
        self::assertLessThan(1.8, $elapsed);
    }

    public function testSqlPublicationAckFailureDoesNotDuplicateRedisClaim(): void
    {
        $id = $this->outbox->enqueue(TelegramService::class, $this->payload(), 'telegram');
        $this->sql->executeStatement("CREATE TRIGGER reject_publish BEFORE UPDATE ON queue_outbox
            WHEN NEW.status = 'published' BEGIN SELECT RAISE(ABORT, 'SQL failure'); END");
        try {
            $this->outbox->publish($id, $this->raw->dispatchWithId(...));
            self::fail('SQL failure must propagate');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame('pending', $this->sql->fetchOne('SELECT status FROM queue_outbox WHERE id = ?', [$id]));
        }
        $job = $this->raw->reserveNextAvailable('sql-outbox:telegram', 0);
        self::assertNotNull($job);
        $before = $this->redis->hGetAll($this->key($id));
        $this->sql->executeStatement('DROP TRIGGER reject_publish');
        self::assertSame(1, $this->backend->publishPending('telegram', 10)['published']);
        self::assertSame($before, $this->redis->hGetAll($this->key($id)));
        self::assertSame(0, $this->redis->lLen($this->prefix . 'queue:sql-outbox:telegram'));
    }

    public function testCompletionBeforeLostAckSkipsRedisRedelivery(): void
    {
        $id = $this->backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
        $job = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($job);
        $calls = 0;
        $operation = static function () use (&$calls): void { $calls++; };
        $this->backend->withLease($job, $operation);
        self::assertNull($this->sql->fetchOne('SELECT payload FROM queue_outbox WHERE id = ?', [$id]));
        self::assertSame($id, $this->backend->dispatch(TelegramService::class, $this->payload(), 'telegram'));
        // Simulate loss of the acknowledgement while Redis still retains the reservation.
        $this->raw->defer($job);
        $this->redis->zAdd($this->prefix . 'queue:sql-outbox:telegram:delayed', 0, $id);
        $redelivery = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($redelivery);
        $this->backend->withLease($redelivery, $operation);
        $this->backend->delete($redelivery);
        self::assertSame(1, $calls);
    }

    public function testRedisExhaustionBeforeCallbackRebuildsOnlyTheDeadCache(): void
    {
        $id = $this->backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
        $old = $this->raw->reserveNextAvailable('sql-outbox:telegram', 0);
        self::assertNotNull($old);
        $this->redis->hSet($this->key($id), 'attempts', 5);
        $this->raw->release($old);
        self::assertSame('dead', $this->redis->hGet($this->key($id), 'state'));
        self::assertSame('published', $this->sql->fetchOne('SELECT status FROM queue_outbox WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT attempts FROM queue_outbox WHERE id = ?', [$id]));
        $this->sql->update('queue_outbox', ['available_at' => 0], ['id' => $id]);
        self::assertSame(1, $this->backend->publishPending('telegram', 1)['published']);
        self::assertSame('ready', $this->redis->hGet($this->key($id), 'state'));
        self::assertSame('0', $this->redis->hGet($this->key($id), 'attempts'));
        self::assertFalse($this->redis->zScore($this->prefix . 'queue:sql-outbox:telegram:dead', $id));
        self::assertFalse($this->raw->renew($old));
        $before = $this->redis->hGetAll($this->key($id));
        $this->sql->update('queue_outbox', ['available_at' => 0], ['id' => $id]);
        self::assertSame(1, $this->backend->publishPending('telegram', 1)['published']);
        self::assertSame($before, $this->redis->hGetAll($this->key($id)));
        self::assertSame(1, $this->redis->lLen($this->prefix . 'queue:sql-outbox:telegram'));
        $job = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($job);
        self::assertSame($id, $job->id);
        $calls = 0;
        $this->backend->withLease($job, static function () use (&$calls): void { $calls++; });
        $this->backend->delete($job);
        self::assertSame(1, $calls);
        self::assertSame(1, (int) $this->sql->fetchOne('SELECT attempts FROM queue_outbox WHERE id = ?', [$id]));
        self::assertNull($this->sql->fetchOne('SELECT payload FROM queue_outbox WHERE id = ?', [$id]));
    }

    public function testMismatchedDeadCacheIsNeverRebuilt(): void
    {
        $id = $this->backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
        $job = $this->raw->reserveNextAvailable('sql-outbox:telegram', 0);
        self::assertNotNull($job);
        $this->raw->fail($job);
        $original = $this->redis->hGetAll($this->key($id));
        $dead = $this->prefix . 'queue:sql-outbox:telegram:dead';
        $score = $this->redis->zScore($dead, $id);
        foreach (['id', 'outbox_id', 'job_class', 'queue_name', 'payload'] as $field) {
            $this->redis->hSet($this->key($id), $field, 'mismatched');
            $before = $this->redis->hGetAll($this->key($id));
            $this->sql->update('queue_outbox', ['available_at' => 0], ['id' => $id]);
            self::assertSame(1, $this->backend->publishPending('telegram', 1)['failed'], $field);
            self::assertSame($before, $this->redis->hGetAll($this->key($id)), $field);
            self::assertSame($score, $this->redis->zScore($dead, $id));
            self::assertSame(0, $this->redis->lLen($this->prefix . 'queue:sql-outbox:telegram'));
            self::assertSame(0, (int) $this->sql->fetchOne('SELECT attempts FROM queue_outbox WHERE id = ?', [$id]));
            $this->redis->hSet($this->key($id), $field, $original[$field]);
        }
    }

    public function testDeadSqlReceiptNeverReactivatesItsRedisCache(): void
    {
        $id = $this->backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
        $job = $this->raw->reserveNextAvailable('sql-outbox:telegram', 0);
        self::assertNotNull($job);
        $this->raw->fail($job);
        $this->sql->update('queue_outbox', ['status' => 'dead', 'available_at' => 0], ['id' => $id]);
        $before = $this->redis->hGetAll($this->key($id));
        self::assertSame(0, $this->backend->publishPending('telegram', 1)['selected']);
        self::assertSame('skipped', $this->outbox->publish($id, $this->raw->dispatchWithId(...)));
        self::assertSame($before, $this->redis->hGetAll($this->key($id)));
        self::assertNull($this->backend->reserveNextAvailable('telegram', 0));
    }

    public function testRedisCacheRebuildCannotResetExhaustedSqlBudget(): void
    {
        $id = $this->backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
        $job = $this->raw->reserveNextAvailable('sql-outbox:telegram', 0);
        self::assertNotNull($job);
        $this->raw->fail($job);
        $this->sql->update('queue_outbox', ['attempts' => 5, 'available_at' => 0], ['id' => $id]);
        $before = $this->redis->hGetAll($this->key($id));
        self::assertSame(1, $this->backend->publishPending('telegram', 1)['skipped']);
        self::assertSame('dead', $this->sql->fetchOne('SELECT status FROM queue_outbox WHERE id = ?', [$id]));
        self::assertSame(5, (int) $this->sql->fetchOne('SELECT attempts FROM queue_outbox WHERE id = ?', [$id]));
        self::assertSame($before, $this->redis->hGetAll($this->key($id)));
    }

    public function testUnmarkedTelegramJobIsQuarantinedWithoutSqlAdoption(): void
    {
        $id = $this->raw->dispatch(TelegramService::class, $this->payload(), 'telegram');
        $job = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($job);
        try {
            $this->backend->withLease($job, static function (): void { self::fail('Unmarked job must not run'); });
            self::fail('Must reject the old protocol');
        } catch (NonRetryableJobException) {
            $this->backend->fail($job);
        }
        self::assertSame('dead', $this->redis->hGet($this->key($id), 'state'));
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM queue_outbox'));
    }

    public function testMarkedJobWithoutSqlReceiptIsQuarantined(): void
    {
        $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $this->raw->dispatchWithId($id, TelegramService::class, $this->payload(), 'telegram');
        $job = $this->backend->reserveNextAvailable('telegram', 0);
        self::assertNotNull($job);
        try {
            $this->backend->withLease($job, static function (): void { self::fail('SQL receipt is required'); });
            self::fail('Must reject a missing SQL receipt');
        } catch (NonRetryableJobException) {
            $this->backend->fail($job);
        }
        self::assertSame('dead', $this->redis->hGet($this->key($id), 'state'));
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM queue_outbox'));
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['update_json' => '{"update_id":42}'];
    }

    private function key(string $id): string
    {
        return $this->prefix . 'queue:job:' . $id;
    }
}
