<?php

declare(strict_types=1);

namespace App\Test\Integration;

use App\Console\ChatMaintenanceCommand;
use App\Job\Web\NewMessageJob;
use App\Services\Auth;
use App\Services\ChatGenerationBusyException;
use App\Services\ChatGenerationState;
use App\Services\ChatMaintenance;
use App\Services\ChatThreadLock;
use App\Services\Queue\QueueRedisConnection;
use App\Services\Queue\RedisQueueBackend;
use App\Services\RedisClient;
use App\Services\Settings;
use App\Services\TelegramGeneration;
use App\Services\TelegramJournal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260912130000;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/** Connection-local SQL tables and random Redis namespaces, never shared table cleanup. */
final class ChatMaintenanceTest extends TestCase
{
    private \Redis $redis;
    private Settings $settings;
    private Connection $sql;
    private ChatGenerationState $states;
    private ChatMaintenance $maintenance;
    private QueueRedisConnection $queue;
    private TelegramJournal $journal;
    private string $prefix;
    private string $user;
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        $port = getenv('CLAIRE_MAINTENANCE_REDIS_PORT');
        $sqlPort = getenv('CLAIRE_MAINTENANCE_SQL_PORT');
        if ($port === false || $sqlPort === false || ! extension_loaded('redis')) {
            self::markTestSkipped('Requires ext-redis and explicit CLAIRE_MAINTENANCE_REDIS_PORT / SQL_PORT');
        }
        $this->prefix = 'maintenance-test:[scope]:' . bin2hex(random_bytes(12)) . ':';
        $this->user = $this->prefix . 'alice';
        $this->settings = new Settings([
            'redis' => ['prefix' => $this->prefix, 'host' => '127.0.0.1', 'port' => (int) $port,
                'timeout' => 2, 'password' => null, 'database' => 0],
            'telegram' => ['bot_token' => '123:test-secret'], 'queue' => [],
        ]);
        $this->redis = new \Redis();
        self::assertTrue($this->redis->connect('127.0.0.1', (int) $port, 2));
        $driver = getenv('CLAIRE_MAINTENANCE_SQL_DRIVER') ?: 'pdo_pgsql';
        $this->sql = DriverManager::getConnection([
            'driver' => $driver, 'host' => '127.0.0.1', 'port' => (int) $sqlPort,
            'user' => getenv('CLAIRE_MAINTENANCE_SQL_USER') ?: ($driver === 'pdo_mysql' ? 'root' : 'postgres'),
            'password' => getenv('CLAIRE_MAINTENANCE_SQL_PASSWORD') ?: 'claire-test-only',
            'dbname' => 'claire_test',
        ]);
        $migration = new Version20260912130000($this->sql, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->sql->executeStatement(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ',
                $query->getStatement()), $query->getParameters(), $query->getTypes());
        }
        require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
        \App\Test\Support\ChatTurnSqlSchema::create($this->sql, true);
        $this->journal = new TelegramJournal($this->sql);
        $client = new RedisClient();
        self::assertTrue($client->connect('127.0.0.1', (int) $port, 2));
        $this->states = new ChatGenerationState($client, $this->settings);
        $this->queue = new QueueRedisConnection($this->settings);
        $this->maintenance = new ChatMaintenance($this->queue, $this->states, $this->sql, $this->settings);
        $this->keys[] = $this->states->key($this->user, 'thread');
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            foreach ($this->keys as $key) {
                $this->redis->del($key);
            }
            $this->redis->close();
            $this->sql->close();
        }
    }

    public function testSqlCompletionRetentionAndPermanentReplayBarrierWithoutHistory(): void
    {
        $id = TelegramJournal::id('123', 'update');
        $generation = new TelegramGeneration($this->settings, $this->sql, $this->states);
        $before = $this->journal->now();
        $generation->run($this->user, 'thread', 'update', static fn (): string => 'SECRET RESPONSE',
            static function (string $response, callable $checkpoint): void {
                $checkpoint('text', static function (): void {});
            });
        $record = $this->journal->load($id);
        self::assertGreaterThanOrEqual($before, $record['completedAt']);
        self::assertLessThanOrEqual($this->journal->now(), $record['completedAt']);
        self::assertSame([], $this->maintenance->compact(7, 10000, apply: true)['counts']);
        $record['completedAt'] -= 8 * 86400;
        $this->journal->save($id, $record);
        $original = $this->journal->load($id);
        self::assertSame(1, $this->maintenance->compact(7, 10000)['counts']['eligible']);
        self::assertSame($original, $this->journal->load($id));
        self::assertSame(1, $this->maintenance->compact(7, 10000, apply: true)['counts']['compacted']);
        $tombstone = $this->journal->load($id);
        self::assertTrue($tombstone['compacted']);
        self::assertArrayNotHasKey('response', $tombstone);
        self::assertSame([], $tombstone['deliveries']);
        self::assertSame(['response' => null, 'deliveries' => null], $this->sql->fetchAssociative(
            'SELECT response, deliveries FROM telegram_generation WHERE id = ?', [$id],
        ));
        $this->states->set($this->user, 'thread', 'deleted', 'deleted', true);
        // Even an unavailable thread lock must not be reached for delivered records.
        $other = DriverManager::getConnection($this->sql->getParams());
        $lock = new ChatThreadLock($other->getNativeConnection(), $this->user, 'thread');
        try {
            $generation->run($this->user, 'thread', 'update',
                static function (): never { self::fail('Must not fetch history or generate'); },
                static function (): never { self::fail('Must not redeliver'); });
        } finally {
            $lock->release();
            $other->close();
        }
        self::assertSame($tombstone, $this->journal->load($id));
    }

    public function testUndatedAmbiguousAndFailedSqlRecordsArePreservedButDeadJobsDoNotBlockDeliveredCompaction(): void
    {
        $id = TelegramJournal::id('123', 'update:42');
        $record = ['userId' => $this->user, 'threadId' => 'thread', 'botId' => '123', 'updateId' => 'update:42',
            'attempted' => true, 'delivered' => true, 'response' => 'PRIVATE'];
        $this->journal->save($id, $record);
        foreach ([null, 0, -1] as $invalidDate) {
            $this->sql->update('telegram_generation', ['completed_at' => $invalidDate], ['id' => $id]);
            $original = $this->journal->load($id);
            self::assertSame([], $this->maintenance->compact(7, 10000, apply: true)['counts']);
            self::assertSame($original, $this->journal->load($id));
        }
        $record['completedAt'] = 1;
        $record['deliveries'] = ['text' => ['status' => 'uncertain']];
        $this->journal->save($id, $record);
        self::assertSame(1, $this->maintenance->compact(7, 10000, apply: true)['counts']['ambiguous']);
        $record['delivered'] = false;
        $this->journal->save($id, $record);
        self::assertSame([], $this->maintenance->compact(7, 10000, apply: true)['counts']);
        $record['delivered'] = true;
        $record['deliveries']['text']['status'] = 'confirmed';
        $this->journal->save($id, $record);
        $this->job('dead', ['update_json' => '{"update_id":42}'], 'App\\Services\\TelegramService');
        self::assertSame(1, $this->maintenance->compact(7, 10000, apply: true)['counts']['compacted']);
    }

    public function testPartialSqlDeliveryHasNoCompletionDateAndResumesWithoutRegeneration(): void
    {
        $id = TelegramJournal::id('123', 'partial');
        $generation = new TelegramGeneration($this->settings, $this->sql, $this->states);
        try {
            $generation->run($this->user, 'thread', 'partial', static fn (): string => 'PRIVATE',
                function (string $response, callable $checkpoint) use ($id): void {
                    $checkpoint('text', static function (): void {});
                    $record = $this->journal->load($id);
                    self::assertNull($record['completedAt']);
                    $checkpoint('voice', static function (): never { throw new \RuntimeException('transport'); });
                });
            self::fail('Transport failure must propagate');
        } catch (\RuntimeException $error) {
            self::assertSame('transport', $error->getMessage());
        }
        $record = $this->journal->load($id);
        self::assertNull($record['completedAt']);
        self::assertSame('PRIVATE', $record['response']);
        self::assertSame('uncertain', $record['deliveries']['voice']['status']);
        self::assertSame([], $this->maintenance->compact(1, 10000, apply: true)['counts']);
        $generation->run($this->user, 'thread', 'partial',
            static function (): never { self::fail('Must not regenerate'); },
            static function (string $response, callable $checkpoint): void {
                $checkpoint('text', static function (): never { self::fail('Text already confirmed'); });
                $checkpoint('voice', static function (): void {});
            });
        $record = $this->journal->load($id);
        self::assertTrue($record['delivered']);
        self::assertIsInt($record['completedAt']);
    }

    public function testReconcilePreservesFenceAndNeverDispatches(): void
    {
        $this->states->set($this->user, 'thread', 'old-message', 'queued', false);
        self::assertSame('orphan', $this->maintenance->diagnose($this->user, 'thread', true, false, 10000)['result']);
        self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
        self::assertSame('reconciled', $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
        self::assertSame('1', $this->states->get($this->user, 'thread')['attempted']);
        self::assertSame('old-message', $this->states->get($this->user, 'thread')['messageId']);
        self::assertSame(-1, $this->redis->ttl($this->states->key($this->user, 'thread')));
        $this->states->set($this->user, 'thread', 'old-message', 'running', true);
        self::assertSame('reconciled', $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
        self::assertSame('1', $this->states->get($this->user, 'thread')['attempted']);
        $backend = new RedisQueueBackend($this->queue, $this->settings, $this->sql);
        $payload = ['threadId' => 'thread', 'sessionId' => 'tab', 'session' => [Auth::USERID => $this->user],
            'messageId' => 'old-message', 'submissionId' => 'submission', 'message' => 'PRIVATE'];
        try {
            $backend->dispatch(NewMessageJob::class, $payload, 'test');
            self::fail('Old message must remain fenced');
        } catch (ChatGenerationBusyException) {
            self::assertSame('error', $this->states->get($this->user, 'thread')['status']);
        }
        $payload['messageId'] = 'new-message';
        $id = $backend->dispatch(NewMessageJob::class, $payload, 'test');
        $this->keys[] = $this->prefix . 'chat:submission:'
            . hash('sha256', json_encode([$this->user, 'submission'], JSON_THROW_ON_ERROR));
        $this->keys[] = $this->prefix . 'queue:job:' . $id;
        $this->keys[] = $this->prefix . 'queue:test';
        self::assertSame($id, $this->states->get($this->user, 'thread')['jobId']);
        self::assertSame('test', $this->states->get($this->user, 'thread')['queue']);
    }

    public function testOldFailuresAndUnrelatedJournalsStillRequireFullRedisProof(): void
    {
        $id = TelegramJournal::id('123', 'old-failure');
        $record = ['botId' => '123', 'updateId' => 'old-failure', 'userId' => $this->user,
            'threadId' => 'thread', 'attempted' => true, 'delivered' => false];
        $this->journal->save($id, $record);
        $this->states->set($this->user, 'thread', 'new-web-message', 'queued', false);
        self::assertSame('orphan', $this->maintenance->diagnose($this->user, 'thread', true, false, 10000)['result']);
        $this->states->set($this->user, 'thread', $id, 'queued', true);
        self::assertSame('sql-journal-retained',
            $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);

        foreach ([[$this->user, 'thread', 'new-web-message'],
            [$this->user, 'other-thread', $id], ['other-user', 'thread', $id],
        ] as [$owner, $thread, $messageId]) {
            $this->sql->update('telegram_generation', ['user_id' => $owner, 'thread_id' => $thread], ['id' => $id]);
            $this->states->set($this->user, 'thread', $messageId, 'queued', false);
            self::assertSame('orphan', $this->maintenance->diagnose($this->user, 'thread', true, false, 10000)['result']);
            self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
            // Passing the SQL check must still require the full Redis proof.
            $key = $this->job('leased', ['threadId' => 'thread', 'session' => [Auth::USERID => $this->user]]);
            self::assertSame('jobs-retained',
                $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
            self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
            $this->redis->del($key);
            self::assertSame('reconciled',
                $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
            self::assertSame('1', $this->states->get($this->user, 'thread')['attempted']);
            self::assertFalse($this->journal->load($id)['delivered']);
        }
    }

    public function testEveryRetainedJobStateBlocksOrphanReconciliation(): void
    {
        $this->states->set($this->user, 'thread', 'message', 'queued', true);
        foreach (['ready', 'delayed', 'leased', 'dead'] as $state) {
            $key = $this->job($state, ['threadId' => 'thread', 'session' => [Auth::USERID => $this->user]]);
            $result = $this->maintenance->diagnose($this->user, 'thread', true, true, 10000);
            self::assertSame('jobs-retained', $result['result']);
            self::assertSame($state, $result['jobs'][0]['state']);
            self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
            $this->redis->del($key);
        }
        $key = $this->job('leased', ['threadId' => 'thread', 'session' => [Auth::USERID => 'other']]);
        self::assertSame('orphan', $this->maintenance->diagnose($this->user, 'thread', true, false, 10000)['result']);
        $this->redis->hset($key, ['payload' => 'broken-secret-json']);
        self::assertSame('ambiguous', $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
    }

    public function testWebJobLinkIsNotTrustedDuringOrAfterTelegramGeneration(): void
    {
        $backend = new RedisQueueBackend($this->queue, $this->settings, $this->sql);
        $id = $backend->dispatch(NewMessageJob::class, [
            'threadId' => 'thread', 'sessionId' => 'tab', 'session' => [Auth::USERID => $this->user],
            'messageId' => 'web-message', 'submissionId' => 'submission', 'message' => 'PRIVATE',
        ], 'test');
        $this->keys[] = $this->prefix . 'chat:submission:'
            . hash('sha256', json_encode([$this->user, 'submission'], JSON_THROW_ON_ERROR));
        $this->keys[] = $this->prefix . 'queue:job:' . $id;
        $this->keys[] = $this->prefix . 'queue:test';
        $this->states->set($this->user, 'thread', 'web-message', 'done', true);
        self::assertSame($id, $this->states->diagnostic($this->user, 'thread')['jobId']);
        $generation = new TelegramGeneration($this->settings, $this->sql, $this->states);
        $generation->run($this->user, 'thread', 'update:99', function (): string {
            $state = $this->redis->hgetall($this->states->key($this->user, 'thread'));
            self::assertSame('running', $state['status']);
            self::assertSame(TelegramJournal::id('123', 'update:99'), $state['messageId']);
            self::assertSame('1', $state['attempted']);
            self::assertNull($this->states->diagnostic($this->user, 'thread')['jobId']);
            self::assertNull($this->states->diagnostic($this->user, 'thread')['queue']);
            return 'PRIVATE';
        }, static function (): void {});
        self::assertNull($this->states->diagnostic($this->user, 'thread')['jobId']);
        self::assertNull($this->states->diagnostic($this->user, 'thread')['queue']);
    }

    public function testUnknownClassesMalformedIdentitiesAndWrongQueueTypesRefuseReconciliation(): void
    {
        $this->states->set($this->user, 'thread', 'message', 'queued', true);
        foreach ([['Unknown\\Job', ['threadId' => 'thread', 'session' => [Auth::USERID => $this->user]]],
            [NewMessageJob::class, ['threadId' => 'thread']],
            [NewMessageJob::class, ['threadId' => 'thread', 'session' => [Auth::USERID => 42]]],
            [NewMessageJob::class, ['session' => [Auth::USERID => $this->user]]],
        ] as [$class, $payload]) {
            $key = $this->job('leased', $payload, $class);
            self::assertSame('ambiguous', $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
            self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
            $this->redis->del($key);
        }
        $this->redis->set($key, 'SECRET WRONG TYPE');
        self::assertSame('ambiguous', $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
        self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
    }

    public function testProofIgnoresMismatchedPointerButStillScansPayloads(): void
    {
        $key = $this->states->key($this->user, 'thread');
        $this->states->set($this->user, 'thread', 'current', 'queued', true);
        $this->redis->hset($key, ['jobId' => 'fixture', 'queue' => 'test', 'jobMessageId' => 'old']);
        $this->job('leased', ['threadId' => 'other', 'session' => [Auth::USERID => 'other']]);
        $result = $this->maintenance->diagnose($this->user, 'thread', true, false, 10000);
        self::assertSame('orphan', $result['result']);
        self::assertNull($result['jobId']);
        $this->redis->hset($key, 'jobMessageId', 'current');
        self::assertSame('jobs-retained', $this->maintenance->diagnose($this->user, 'thread', true, false, 10000)['result']);
        $this->redis->hdel($key, 'jobMessageId');
        $this->job('leased', ['threadId' => 'thread', 'session' => [Auth::USERID => $this->user]]);
        self::assertSame('jobs-retained', $this->maintenance->diagnose($this->user, 'thread', true, false, 10000)['result']);
        $this->job('leased', ['update_json' => '{"update_id":999}'], 'App\\Services\\TelegramService');
        self::assertSame('telegram-job-retained', $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
        self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
    }

    public function testActiveSqlLocksBlockBothMutations(): void
    {
        $this->states->set($this->user, 'thread', 'message', 'queued', true);
        $other = DriverManager::getConnection($this->sql->getParams());
        $lock = new ChatThreadLock($other->getNativeConnection(), $this->user, 'thread');
        try {
            $result = $this->maintenance->diagnose($this->user, 'thread', true, true, 10000);
            self::assertSame('locked', $result['result']);
            self::assertSame('queued', $result['status']);
            self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
        } finally {
            $lock->release();
        }
        $id = TelegramJournal::id('123', 'locked');
        $record = ['userId' => $this->user, 'threadId' => 'thread', 'botId' => '123',
            'updateId' => 'locked', 'attempted' => true, 'delivered' => true, 'completedAt' => 1];
        $this->journal->save($id, $record);
        $lock = new ChatThreadLock($other->getNativeConnection(), 'telegram-journal', $id);
        try {
            self::assertSame(1, $this->maintenance->compact(7, 10000, apply: true)['counts']['locked']);
        } finally {
            $lock->release();
            $other->close();
        }
    }

    public function testCommandArgumentValidationDryRunAndRedaction(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($this->maintenance);
        $tester = new CommandTester(new ChatMaintenanceCommand($container));
        self::assertSame(2, $tester->execute([]));
        self::assertSame(2, $tester->execute(['--user' => $this->user]));
        self::assertSame(2, $tester->execute(['--compact' => true, '--reconcile' => true]));
        self::assertSame(2, $tester->execute(['--compact' => true, '--limit' => '12bad']));
        self::assertSame(0, $tester->execute(['--compact' => true, '--limit' => '10000']));
        self::assertStringContainsString('"counts":{}', $tester->getDisplay());
        $this->states->set($this->user, 'thread', 'message', 'queued', true);
        $this->job('leased', ['threadId' => 'thread', 'session' => [Auth::USERID => $this->user,
            'token' => 'SECRET TOKEN'], 'message' => 'SECRET RESPONSE']);
        self::assertSame(0, $tester->execute(['--user' => $this->user, '--thread' => 'thread', '--reconcile' => true]));
        self::assertStringNotContainsString('SECRET', $tester->getDisplay());
        self::assertStringContainsString('jobs-retained', $tester->getDisplay());
        self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
    }

    public function testAbsenceProofRemainsUsableWithManyUnrelatedRetainedKeys(): void
    {
        $this->states->set($this->user, 'thread', 'message', 'queued', false);
        $this->redis->multi(\Redis::PIPELINE);
        for ($index = 0; $index < 2500; $index++) {
            $key = $this->prefix . 'retained-tombstone:' . $index;
            $this->keys[] = $key;
            $this->redis->set($key, '1');
        }
        self::assertCount(2500, $this->redis->exec());
        self::assertSame('orphan', $this->maintenance->diagnose($this->user, 'thread', true, false, 100)['result']);
        self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
    }

    public function testIncompleteProofNeverMutates(): void
    {
        $this->states->set($this->user, 'thread', 'message', 'queued', true);
        $this->job('ready', ['threadId' => 'other', 'session' => [Auth::USERID => 'other']]);
        self::assertSame('incomplete', $this->maintenance->diagnose($this->user, 'thread', true, true, 1)['result']);
        self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
    }

    public function testCliSqlKeysetCursorTraversesPagesAndRestartsApplyAtZero(): void
    {
        $originals = [];
        for ($i = 0; $i < 5; $i++) {
            $update = 'cursor-' . $i;
            $id = TelegramJournal::id('123', $update);
            $record = ['userId' => $this->user, 'threadId' => 'thread', 'botId' => '123',
                'updateId' => $update, 'attempted' => true, 'delivered' => true,
                'completedAt' => 1 + intdiv($i, 2), 'response' => 'PRIVATE BODY'];
            $this->journal->save($id, $record);
            $originals[$id] = $this->journal->load($id);
        }
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($this->maintenance);
        $tester = new CommandTester(new ChatMaintenanceCommand($container));
        foreach ([false, true] as $apply) {
            $cursor = '0';
            $count = 0;
            $seen = [];
            for ($page = 0; $page < 5; $page++) {
                $arguments = ['--compact' => true, '--limit' => '1', '--cursor' => $cursor];
                if ($apply) {
                    $arguments['--apply'] = true;
                }
                self::assertSame(0, $tester->execute($arguments));
                $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($apply, $result['apply']);
                self::assertStringNotContainsString('PRIVATE', $tester->getDisplay());
                $cursor = $result['cursor'];
                if ($page < 4) {
                    self::assertStringStartsWith('sql:v1:', $cursor);
                    self::assertNotContains($cursor, $seen);
                    $seen[] = $cursor;
                }
                $count += $result['counts'][$apply ? 'compacted' : 'eligible'];
                if ($cursor === '0') {
                    break;
                }
            }
            self::assertSame('0', $cursor, 'Continuation must finish instead of restarting at the first page');
            self::assertSame(5, $count, 'Keyset traversal must visit each eligible row exactly once');
            if (! $apply) {
                foreach ($originals as $id => $record) {
                    self::assertSame($record, $this->journal->load($id));
                }
            }
        }
        foreach (array_keys($originals) as $id) {
            $record = $this->journal->load($id);
            self::assertTrue($record['delivered']);
            self::assertTrue($record['compacted']);
            self::assertArrayNotHasKey('response', $record);
        }
        self::assertSame(0, $tester->execute(['--compact' => true, '--apply' => true, '--cursor' => '0']));
        self::assertSame(['apply' => true, 'cursor' => '0', 'counts' => []],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testDanglingQueueIndexesAreAmbiguous(): void
    {
        $this->states->set($this->user, 'thread', 'message', 'queued', true);
        foreach (['pending', 'delayed', 'leased', 'dead'] as $type) {
            $key = $this->prefix . 'queue:test:' . $type;
            $this->keys[] = $key;
            if ($type === 'pending') {
                $this->redis->lpush($key, 'missing-payload');
            } else {
                $this->redis->zadd($key, 1, 'missing-payload');
            }
            self::assertSame('ambiguous', $this->maintenance->diagnose($this->user, 'thread', true, true, 10000)['result']);
            self::assertSame('queued', $this->states->get($this->user, 'thread')['status']);
            $this->redis->del($key);
        }
    }

    public function testAtomicReconciliationRereadsStateBeforeMutation(): void
    {
        $this->states->set($this->user, 'thread', 'message', 'queued', true);
        $connection = new class ($this->settings, $this->redis) extends QueueRedisConnection {
            public function __construct(Settings $settings, private \Redis $inspector)
            {
                parent::__construct($settings);
            }

            public function evaluate(string $script, array $arguments, int $keyCount): mixed
            {
                $this->inspector->hset($arguments[0], 'status', 'done');
                return parent::evaluate($script, $arguments, $keyCount);
            }
        };
        $service = new ChatMaintenance($connection, $this->states, $this->sql, $this->settings);
        self::assertSame('not-active', $service->diagnose($this->user, 'thread', true, true, 10000)['result']);
        self::assertSame('done', $this->states->get($this->user, 'thread')['status']);
    }

    /** @param array<string, mixed> $payload */
    private function job(string $state, array $payload, string $class = NewMessageJob::class): string
    {
        $key = $this->prefix . 'queue:job:fixture';
        $this->keys[] = $key;
        $this->redis->hset($key, ['id' => 'fixture', 'queue_name' => 'test', 'state' => $state,
            'job_class' => $class, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        return $key;
    }
}
