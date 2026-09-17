<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Console\ChatMaintenanceCommand;
use App\Services\ChatGenerationState;
use App\Services\ChatMaintenance;
use App\Services\ChatThreadLock;
use App\Services\Queue\QueueRedisConnection;
use App\Services\RedisClient;
use App\Services\Settings;
use App\Services\TelegramJournal;
use App\Test\Support\TelegramSqlSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260912130000;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/** Actual migrations in memory or connection-local temporary tables; no shared table cleanup. */
final class ChatMaintenanceSqlTest extends TestCase
{
    private Connection $sql;
    private TelegramJournal $journal;
    private Settings $settings;
    private string $user;

    protected function setUp(): void
    {
        $port = getenv('CLAIRE_MAINTENANCE_SQL_PORT');
        $driver = getenv('CLAIRE_MAINTENANCE_SQL_DRIVER') ?: 'pdo_pgsql';
        $params = $port === false ? ['driver' => 'pdo_sqlite', 'memory' => true] : [
            'driver' => $driver, 'host' => '127.0.0.1', 'port' => (int) $port,
            'user' => getenv('CLAIRE_MAINTENANCE_SQL_USER') ?: ($driver === 'pdo_mysql' ? 'root' : 'postgres'),
            'password' => getenv('CLAIRE_MAINTENANCE_SQL_PASSWORD') ?: 'claire-test-only',
            'dbname' => 'claire_test',
        ];
        $this->sql = DriverManager::getConnection($params + ['wrapperClass' => MaintenanceSqlConnection::class]);
        require_once Settings::getAppRoot() . '/test/Support/TelegramSqlSchema.php';
        if ($port === false) {
            TelegramSqlSchema::create($this->sql);
            require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
            \App\Test\Support\ChatTurnSqlSchema::create($this->sql);
        } else {
            // Server fixtures shadow shared table names for this connection only.
            $migration = new Version20260912130000($this->sql, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->sql->executeStatement(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ',
                    $query->getStatement()), $query->getParameters(), $query->getTypes());
            }
            require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
            \App\Test\Support\ChatTurnSqlSchema::create($this->sql, true);
        }
        $this->journal = new TelegramJournal($this->sql);
        $prefix = 'maintenance-sql:' . bin2hex(random_bytes(8)) . ':';
        $this->user = $prefix . 'alice';
        $this->settings = new Settings(['redis' => ['prefix' => $prefix]]);
    }

    protected function tearDown(): void
    {
        $this->sql->close();
    }

    public function testIndexedKeysetPaginationAndPermanentTombstonesWithoutRedis(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->record('page-' . $i);
        }
        sort($ids);
        $service = $this->service();
        $first = $service->compact(7, 2);
        self::assertSame(['eligible' => 2], $first['counts']);
        self::assertSame([1, $ids[1]], ChatMaintenance::sqlCursor($first['cursor']));
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT SUM(compacted) FROM telegram_generation'));
        $cursor = '0';
        $count = 0;
        do {
            $page = $service->compact(7, 2, $cursor, true);
            $count += $page['counts']['compacted'];
            $cursor = $page['cursor'];
        } while ($cursor !== '0');
        self::assertSame(5, $count);
        self::assertSame(5, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM telegram_generation '
            . 'WHERE delivered = 1 AND attempted = 1 AND compacted = 1 AND response IS NULL AND deliveries IS NULL'));
        self::assertSame([], $service->compact(7, 2, apply: true)['counts']);
        if ($this->sql->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            self::assertSame(['delivered', 'compacted', 'completed_at', 'id'],
                array_column($this->sql->fetchAllAssociative("PRAGMA index_info('idx_telegram_generation_retention')"), 'name'));
            $plan = $this->sql->fetchAllAssociative('EXPLAIN QUERY PLAN SELECT id, completed_at, revision '
                . 'FROM telegram_generation WHERE delivered = 1 AND compacted = 0 '
                . 'AND completed_at > 0 AND completed_at <= 100 ORDER BY completed_at, id LIMIT 3');
            self::assertStringContainsString('idx_telegram_generation_retention',
                json_encode($plan, JSON_THROW_ON_ERROR));
        }
    }

    public function testRecentActiveUndatedAndUnconfirmedRecordsAreNotCompacted(): void
    {
        $this->record('recent', ['completedAt' => $this->journal->now()]);
        $this->record('active', ['delivered' => false]);
        $undated = $this->record('undated');
        $this->sql->update('telegram_generation', ['completed_at' => null], ['id' => $undated]);
        $this->record('failed', ['attempted' => false]);
        $this->record('uncertain', ['deliveries' => ['text' => ['status' => 'uncertain']]]);
        self::assertSame(['ambiguous' => 2], $this->service()->compact(7, 100, apply: true)['counts']);
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT SUM(compacted) FROM telegram_generation'));
    }

    public function testGlobalJournalLockBlocksCompaction(): void
    {
        $id = $this->record('busy');
        $other = DriverManager::getConnection($this->sql->getParams());
        $lock = new ChatThreadLock($other->getNativeConnection(), 'telegram-journal', $id);
        try {
            self::assertSame(['locked' => 1], $this->service()->compact(7, 100, apply: true)['counts']);
            self::assertFalse($this->journal->load($id)['compacted']);
        } finally {
            $lock->release();
            $other->close();
        }
    }

    public function testSqlRevisionCasRejectsChangeAfterReadingConfirmation(): void
    {
        $id = $this->record('cas');
        $this->sql->changeBeforeCompact = true;
        self::assertSame(['changed' => 1], $this->service()->compact(7, 100, apply: true)['counts']);
        self::assertFalse($this->journal->load($id)['compacted']);
        self::assertSame('SECRET RESPONSE', $this->journal->load($id)['response']);
    }

    public function testCurrentUndeliveredSqlJournalBlocksReconciliation(): void
    {
        $id = $this->record('active', ['delivered' => false]);
        self::assertSame('sql-journal-retained',
            $this->service($id)->diagnose($this->user, 'thread', true, true, 100)['result']);
    }

    public function testRunningSqlTurnBlocksRedisOnlyRepairEvenWithStaleProjection(): void
    {
        $journal = new \App\Services\ChatTurnJournal($this->sql);
        $journal->begin('message-running', $this->user, 'thread', 'web', 'message-running');
        $result = $this->service('message-stale')->diagnose($this->user, 'thread', true, true, 100);
        self::assertSame('chat-turn-retained', $result['result']);
        self::assertSame('running', $result['turnStatus']);
        self::assertSame('running', $journal->get('message-running')['status']);
    }

    public function testCommandCursorsDryRunAndRedaction(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $tester = new CommandTester(new ChatMaintenanceCommand($container));
        foreach (['123', 'redis:12', 'sql:invalid', 'sql:' . base64_encode('[1,"invalid-id"]')] as $cursor) {
            self::assertSame(2, $tester->execute(['--compact' => true, '--cursor' => $cursor]));
        }
        $this->record('cli');
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($this->service());
        $tester = new CommandTester(new ChatMaintenanceCommand($container));
        self::assertSame(0, $tester->execute(['--compact' => true]));
        self::assertStringContainsString('"eligible":1', $tester->getDisplay());
        self::assertStringContainsString('"apply":false', $tester->getDisplay());
        self::assertStringNotContainsString('SECRET', $tester->getDisplay());
    }

    public function testCompactionRefusesOuterTransaction(): void
    {
        $this->record('transaction');
        $this->sql->beginTransaction();
        try {
            $this->expectException(\RuntimeException::class);
            $this->service()->compact(7, 100, apply: true);
        } finally {
            $this->sql->rollBack();
        }
    }

    public function testCompactionRefusesNativePdoTransactionWithoutMutatingOrEndingIt(): void
    {
        $id = $this->record('native-transaction');
        $original = $this->sql->fetchAssociative('SELECT * FROM telegram_generation WHERE id = ?', [$id]);
        $native = $this->sql->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        self::assertTrue($native->beginTransaction());
        try {
            self::assertFalse($this->sql->isTransactionActive());
            $this->sql->update('telegram_generation', ['response' => 'CALLER UNCOMMITTED RESPONSE'], ['id' => $id]);
            $pending = $this->sql->fetchAssociative('SELECT * FROM telegram_generation WHERE id = ?', [$id]);
            foreach ([false, true] as $apply) {
                try {
                    $this->service()->compact(7, 100, apply: $apply);
                    self::fail('Native PDO transaction must refuse compaction');
                } catch (\RuntimeException $error) {
                    self::assertSame('Compaction requires an independent short commit', $error->getMessage());
                }
                self::assertTrue($native->inTransaction(), 'Maintenance must neither commit nor roll back its caller');
                self::assertFalse($this->sql->isTransactionActive());
                self::assertSame($pending,
                    $this->sql->fetchAssociative('SELECT * FROM telegram_generation WHERE id = ?', [$id]));
            }
        } finally {
            if ($native->inTransaction()) {
                $native->rollBack();
            }
        }
        self::assertSame($original, $this->sql->fetchAssociative('SELECT * FROM telegram_generation WHERE id = ?', [$id]));
    }

    public function testRedisWebProofRemainsConservative(): void
    {
        $port = getenv('CLAIRE_MAINTENANCE_REDIS_PORT');
        if ($port === false || ! extension_loaded('redis')) {
            self::markTestSkipped('Requires explicit isolated Redis port and ext-redis');
        }
        $prefix = (string) $this->settings->get('redis.prefix');
        $settings = new Settings(['redis' => ['prefix' => $prefix, 'host' => '127.0.0.1',
            'port' => (int) $port, 'timeout' => 2, 'password' => null, 'database' => 0]]);
        $redis = new RedisClient();
        self::assertTrue($redis->connect('127.0.0.1', (int) $port, 2));
        $states = new ChatGenerationState($redis, $settings);
        $service = new ChatMaintenance(new QueueRedisConnection($settings), $states, $this->sql, $settings);
        $job = $prefix . 'queue:job:fixture';
        try {
            $states->set($this->user, 'thread', 'message', 'queued', false);
            self::assertSame('orphan', $service->diagnose($this->user, 'thread', true, false, 10000)['result']);
            self::assertSame('queued', $states->get($this->user, 'thread')['status']);
            $redis->hset($job, ['id' => 'fixture', 'queue_name' => 'test', 'state' => 'dead',
                'job_class' => 'App\\Job\\Web\\NewMessageJob', 'payload' => json_encode([
                    'threadId' => 'thread', 'session' => [\App\Services\Auth::USERID => $this->user],
                    'message' => 'SECRET MESSAGE',
                ], JSON_THROW_ON_ERROR)]);
            $result = $service->diagnose($this->user, 'thread', true, true, 10000);
            self::assertSame('jobs-retained', $result['result']);
            self::assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
            self::assertSame('queued', $states->get($this->user, 'thread')['status']);
            $redis->del($job);
            self::assertSame('reconciled', $service->diagnose($this->user, 'thread', true, true, 10000)['result']);
            self::assertSame('1', $states->get($this->user, 'thread')['attempted']);
        } finally {
            $redis->del([$job, $states->key($this->user, 'thread')]);
        }
    }

    private function service(string $messageId = 'message'): ChatMaintenance
    {
        $queue = $this->createMock(QueueRedisConnection::class);
        $queue->expects(self::never())->method('evaluate');
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturn(['status' => 'queued', 'attempted' => '1', 'messageId' => $messageId]);
        return new ChatMaintenance($queue, new ChatGenerationState($redis, $this->settings), $this->sql, $this->settings);
    }

    /** @param array<string, mixed> $overrides */
    private function record(string $update, array $overrides = []): string
    {
        $id = TelegramJournal::id('123', $update);
        $record = $overrides + ['botId' => '123', 'updateId' => $update, 'userId' => $this->user,
            'threadId' => 'thread', 'attempted' => true, 'delivered' => true, 'completedAt' => 1,
            'response' => 'SECRET RESPONSE', 'deliveries' => ['text' => ['status' => 'confirmed']]];
        $this->journal->save($id, $record);
        return $id;
    }
}

/** Inject a non-cooperating writer exactly between validation and conditional SQL UPDATE. */
final class MaintenanceSqlConnection extends Connection
{
    public bool $changeBeforeCompact = false;

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if ($this->changeBeforeCompact && str_starts_with($sql, 'UPDATE telegram_generation SET compacted = 1')) {
            $this->changeBeforeCompact = false;
            parent::executeStatement('UPDATE telegram_generation SET revision = revision + 1 WHERE id = ?', [$params[1]]);
        }
        return parent::executeStatement($sql, $params, $types);
    }
}
