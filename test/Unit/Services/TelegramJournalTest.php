<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatGenerationBusyException;
use App\Services\ChatGenerationState;
use App\Services\Queue\NonRetryableJobException;
use App\Services\RedisClient;
use App\Services\Settings;
use App\Services\TelegramGeneration;
use App\Services\TelegramJournal;
use App\Test\Support\TelegramSqlSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelegramJournalTest extends TestCase
{
    private Connection $connection;
    private TelegramJournal $journal;

    protected function setUp(): void
    {
        require_once Settings::getAppRoot() . '/test/Support/TelegramSqlSchema.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        TelegramSqlSchema::create($this->connection);
        $this->journal = new TelegramJournal($this->connection);
    }

    public function testLostResponseCommitAcknowledgementCanResumeWithoutRegeneration(): void
    {
        $this->connection->close();
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true,
            'wrapperClass' => JournalCommitReplyLostConnection::class]);
        TelegramSqlSchema::create($this->connection);
        $this->journal = new TelegramJournal($this->connection);
        $redis = $this->createStub(RedisClient::class);
        $states = [];
        $redis->method('hgetall')->willReturnCallback(static function (string $key) use (&$states): array {
            return $states[$key] ?? [];
        });
        $redis->method('hset')->willReturnCallback(static function (string $key, array $values) use (&$states): int {
            $states[$key] = array_merge($states[$key] ?? [], $values);
            return 1;
        });
        $generation = $this->generation($redis);
        $calls = 0;
        $generate = static function () use (&$calls): string { $calls++; return 'persisted answer'; };
        try {
            $generation->run('user', 'thread', 'commit-reply', $generate,
                static function (): void { self::fail('Delivery waits for confirmed persistence'); });
            self::fail('Commit acknowledgement failure was swallowed');
        } catch (RuntimeException $error) {
            self::assertSame('Response commit reply lost', $error->getMessage());
            self::assertNotInstanceOf(\App\Services\Queue\NonRetryableJobException::class, $error);
        }
        $id = TelegramJournal::id('123', 'commit-reply');
        self::assertSame('persisted answer', $this->journal->load($id)['response']);
        self::assertSame('error', array_values($states)[0]['status']);
        $generation->run('user', 'different-thread', 'commit-reply', $generate,
            static function (string $response): void { self::assertSame('persisted answer', $response); });
        self::assertSame(1, $calls);
        self::assertTrue($this->journal->load($id)['delivered']);
        self::assertSame('done', array_values($states)[0]['status']);
    }

    public function testDefaultsCasAndDatabaseCompletionTime(): void
    {
        $record = ['botId' => '123', 'updateId' => 'update', 'userId' => 'user', 'threadId' => 'thread'];
        $this->journal->save('id', $record);
        self::assertSame(1, $record['_revision']);
        $stale = $record;
        $record['attempted'] = true;
        $record['response'] = 'answer';
        $record['delivered'] = true;
        $before = $this->journal->now();
        $this->journal->save('id', $record);
        self::assertGreaterThanOrEqual($before, $record['completedAt']);
        self::assertSame(2, $this->journal->load('id')['_revision']);
        try {
            $this->journal->save('id', $stale);
            self::fail('CAS must reject stale writes');
        } catch (RuntimeException) {
            self::assertSame(1, $stale['_revision']);
            self::assertSame('answer', $this->journal->load('id')['response']);
        }
    }

    public function testOuterTransactionCannotHideAttemptCommit(): void
    {
        $this->connection->beginTransaction();
        try {
            $record = ['attempted' => true];
            $this->expectException(RuntimeException::class);
            $this->journal->save('id', $record);
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testIdentitiesAreRequiredBySql(): void
    {
        $record = ['attempted' => true];
        $this->expectException(\Doctrine\DBAL\Exception\NotNullConstraintViolationException::class);
        $this->journal->save('missing-identities', $record);
    }

    public static function transactionOwners(): array
    {
        return ['DBAL' => [false], 'native PDO' => [true]];
    }

    #[DataProvider('transactionOwners')]
    public function testGenerationRejectsOuterTransactionBeforeAgent(bool $native): void
    {
        $owner = $native ? $this->connection->getNativeConnection() : $this->connection;
        $owner->beginTransaction();
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::never())->method('hgetall');
        try {
            $this->generation($redis)->run('user', 'thread', 'update',
                static function (): string { self::fail('No agent inside an external transaction'); },
                static function (): void { self::fail('No delivery'); });
            self::fail('External transaction must be rejected');
        } catch (RuntimeException $error) {
            self::assertSame('Telegram journal requires an independent short commit', $error->getMessage());
            self::assertTrue($this->connection->getNativeConnection()->inTransaction());
        } finally {
            $owner->rollBack();
        }
        self::assertNull($this->journal->load(TelegramJournal::id('123', 'update')));
    }

    public function testSecondConnectionSeesAttemptBeforeAgentAndResponseSurvivesDeliveryFailure(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'telegram-journal-');
        self::assertNotFalse($path);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $observer = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        try {
            TelegramSqlSchema::create($this->connection);
            $redis = $this->createStub(RedisClient::class);
            $redis->method('hgetall')->willReturn([]);
            $redis->method('hset')->willReturn(1);
            $id = TelegramJournal::id('123', 'update');
            $journal = new TelegramJournal($observer);
            $calls = 0;
            $generate = function () use ($journal, $id, &$calls): string {
                $calls++;
                self::assertFalse($this->connection->isTransactionActive());
                self::assertFalse($this->connection->getNativeConnection()->inTransaction());
                self::assertTrue($journal->load($id)['attempted']);
                self::assertArrayNotHasKey('response', $journal->load($id));
                return 'committed response';
            };
            try {
                $this->generation($redis)->run('user', 'thread', 'update', $generate,
                    function (string $answer, callable $checkpoint) use ($journal, $id): void {
                        self::assertFalse($this->connection->isTransactionActive());
                        self::assertFalse($this->connection->getNativeConnection()->inTransaction());
                        self::assertSame($answer, $journal->load($id)['response']);
                        $checkpoint('text:0', function () use ($journal, $id): void {
                            self::assertFalse($this->connection->isTransactionActive());
                            self::assertFalse($this->connection->getNativeConnection()->inTransaction());
                            self::assertSame('sending', $journal->load($id)['deliveries']['text:0']['status']);
                            throw new RuntimeException('delivery failed');
                        });
                    });
                self::fail('Delivery must fail');
            } catch (RuntimeException $error) {
                self::assertSame('delivery failed', $error->getMessage());
            }
            self::assertSame('committed response', $journal->load($id)['response']);
            self::assertSame('uncertain', $journal->load($id)['deliveries']['text:0']['status']);
            $this->generation($redis)->run('user', 'thread', 'update', $generate,
                static function (string $answer): void { self::assertSame('committed response', $answer); });
            self::assertSame(1, $calls);
            self::assertTrue($journal->load($id)['delivered']);
        } finally {
            $observer->close();
            $this->connection->close();
            unlink($path);
        }
    }

    private function generation(RedisClient $redis): TelegramGeneration
    {
        $settings = new Settings(['telegram' => ['bot_token' => '123:secret'], 'redis' => ['prefix' => 'test:']]);
        return new TelegramGeneration($settings, $this->connection, new ChatGenerationState($redis, $settings));
    }

    public function testRedisLossAfterAgentDoesNotReplayAndDeliveryResumes(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $state = [];
        $lost = false;
        $redis->method('hgetall')->willReturnCallback(static function () use (&$state, &$lost): array {
            if ($lost) {
                throw new RuntimeException('Redis lost');
            }
            return $state;
        });
        $redis->method('hset')->willReturnCallback(static function ($key, $data) use (&$state): int {
            $state = $data;
            return 1;
        });
        $generation = $this->generation($redis);
        $calls = $sends = 0;
        $generate = function () use (&$calls, &$lost): string {
            $calls++;
            self::assertFalse($this->connection->isTransactionActive());
            self::assertTrue($this->journal->load(TelegramJournal::id('123', 'update'))['attempted']);
            $lost = true;
            return 'durable';
        };
        $deliver = static function ($answer, $checkpoint) use (&$sends): void {
            self::assertSame('durable', $answer);
            $checkpoint('text:0', static function () use (&$sends): void {
                if (++$sends === 1) {
                    throw new RuntimeException('delivery failed');
                }
            });
        };
        try {
            $generation->run('user', 'thread', 'update', $generate, $deliver);
            self::fail('Delivery must fail');
        } catch (RuntimeException $error) {
            self::assertSame('delivery failed', $error->getMessage());
        }
        self::assertSame('uncertain', $this->journal->load(TelegramJournal::id('123', 'update'))
            ['deliveries']['text:0']['status']);
        $generation->run('user', 'different-thread', 'update', $generate, $deliver);
        $generation->run('user', 'different-thread', 'update', $generate, $deliver);
        self::assertSame(1, $calls);
        self::assertSame(2, $sends);
    }

    public function testAttemptBarrierSurvivesRedisLoss(): void
    {
        $record = ['botId' => '123', 'updateId' => 'update',
            'userId' => 'user', 'threadId' => 'thread', 'attempted' => true];
        $this->journal->save(TelegramJournal::id('123', 'update'), $record);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willThrowException(new RuntimeException('Redis lost'));
        $this->expectException(NonRetryableJobException::class);
        $this->generation($redis)->run('user', 'thread', 'update',
            static function (): string { self::fail('No replay'); }, static function (): void { self::fail('No delivery'); });
    }

    public function testSelfStaleProjectionCanResumeBeforeSqlAttempt(): void
    {
        $id = TelegramJournal::id('123', 'update');
        $record = ['botId' => '123', 'updateId' => 'update',
            'userId' => 'user', 'threadId' => 'thread', 'attempted' => false];
        $this->journal->save($id, $record);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('hgetall')->willReturn(['messageId' => $id, 'status' => 'running', 'attempted' => '0']);
        $redis->expects(self::exactly(3))->method('hset')->willReturn(1);
        $this->generation($redis)->run('user', 'thread', 'update',
            static fn (): string => 'answer', static function (string $answer): void {
                self::assertSame('answer', $answer);
            });
        self::assertTrue($this->journal->load($id)['delivered']);
    }

    public function testJournalLockIsIndependentOfUser(): void
    {
        $lock = new \App\Services\ChatThreadLock(
            new \PDO('sqlite::memory:'), 'telegram-journal', TelegramJournal::id('123', 'update'),
        );
        try {
            $this->expectException(ChatGenerationBusyException::class);
            $this->generation($this->createStub(RedisClient::class))->run('different-user', 'thread', 'update',
                static function (): string { self::fail('No agent'); }, static function (): void { self::fail('No send'); });
        } finally {
            $lock->release();
        }
    }

    public function testFailedInitialProjectionLeavesSqlReplaySafe(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturn([]);
        $redis->method('hset')->willReturn(false);
        try {
            $this->generation($redis)->run('user', 'thread', 'update',
                static function (): string { self::fail('No agent'); }, static function (): void { self::fail('No send'); });
            self::fail('Projection failure must defer');
        } catch (ChatGenerationBusyException) {
            self::assertFalse($this->journal->load(TelegramJournal::id('123', 'update'))['attempted']);
        }
    }

    public function testOtherGenerationProjectionIsNotOverwrittenWhenResumingResponse(): void
    {
        $record = ['botId' => '123', 'updateId' => 'update',
            'userId' => 'user', 'threadId' => 'thread', 'attempted' => true, 'response' => 'answer'];
        $this->journal->save(TelegramJournal::id('123', 'update'), $record);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('hgetall')->willReturn(['messageId' => 'other', 'status' => 'running']);
        $redis->expects(self::never())->method('hset');
        $this->generation($redis)->run('user', 'thread', 'update',
            static function (): string { self::fail('No agent'); }, static function (string $answer): void {
                self::assertSame('answer', $answer);
            });
    }

    public function testKnownOwnerMismatchRejectsEvenDeliveredTombstone(): void
    {
        $record = ['botId' => '123', 'updateId' => 'update', 'threadId' => 'thread',
            'userId' => 'other', 'attempted' => true, 'delivered' => true, 'compacted' => true];
        $this->journal->save(TelegramJournal::id('123', 'update'), $record);
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::never())->method('hgetall');
        $this->expectException(NonRetryableJobException::class);
        $this->generation($redis)->run('user', 'thread', 'update',
            static function (): string { self::fail('No agent'); }, static function (): void { self::fail('No send'); });
    }

    public function testTombstoneDoesNotReadRedisOrHistory(): void
    {
        $record = ['botId' => '123', 'updateId' => 'update', 'threadId' => 'thread', 'userId' => 'user',
            'attempted' => true, 'delivered' => true, 'compacted' => true,
        ];
        $this->journal->save(TelegramJournal::id('123', 'update'), $record);
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::never())->method('hgetall');
        $this->generation($redis)->run('user', 'thread', 'update',
            static function (): string { self::fail('No agent'); }, static function (): void { self::fail('No delivery'); });
    }

    public static function engines(): array
    {
        return ['SQLite' => ['SQLITE'], 'MySQL' => ['MYSQL'], 'PostgreSQL' => ['PGSQL']];
    }

    #[DataProvider('engines')]
    public function testDatabaseMatrix(string $engine): void
    {
        if ($engine === 'SQLITE') {
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        } else {
            $prefix = 'CLAIRE_CHAT_TEST_' . $engine;
            $dsn = getenv($prefix . '_DSN');
            if (! is_string($dsn) || $dsn === '') {
                self::markTestSkipped('Requires disposable ' . $prefix . '_DSN');
            }
            $params = ['driver' => 'pdo_' . strtolower($engine),
                'user' => getenv($prefix . '_USER') ?: '', 'password' => getenv($prefix . '_PASSWORD') ?: ''];
            foreach (explode(';', substr($dsn, strpos($dsn, ':') + 1)) as $part) {
                [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
                $params[$name] = $value;
            }
            if (! str_starts_with($params['dbname'] ?? '', 'telegram_journal_')) {
                self::markTestSkipped('Requires a dedicated telegram_journal_* database, never a shared database');
            }
            $connection = DriverManager::getConnection($params);
        }
        TelegramSqlSchema::create($connection);
        try {
            $journal = new TelegramJournal($connection);
            $record = ['botId' => '123', 'updateId' => 'update', 'threadId' => 'thread', 'userId' => 'user',
                'attempted' => true, 'response' => str_repeat('a', 70000)];
            $journal->save('matrix', $record);
            $stale = $record;
            $record['delivered'] = true;
            $journal->save('matrix', $record);
            self::assertSame(70000, strlen($journal->load('matrix')['response']));
            self::assertSame(2, $journal->load('matrix')['_revision']);
            self::assertGreaterThan(0, $journal->load('matrix')['completedAt']);
            $this->expectException(RuntimeException::class);
            $journal->save('matrix', $stale);
        } finally {
            TelegramSqlSchema::drop($connection);
        }
    }
}

final class JournalCommitReplyLostConnection extends Connection
{
    private bool $loseReply = true;

    public function transactional(\Closure $func): mixed
    {
        $result = parent::transactional($func);
        if ($this->loseReply && $this->fetchOne('SELECT response FROM telegram_generation') !== null) {
            $this->loseReply = false;
            throw new RuntimeException('Response commit reply lost');
        }
        return $result;
    }
}
