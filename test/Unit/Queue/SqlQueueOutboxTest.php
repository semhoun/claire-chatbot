<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Job\Telegram\StartThreadJob;
use App\Services\ChatGenerationBusyException;
use App\Services\ChatThreadLock;
use App\Services\Queue\NonRetryableJobException;
use App\Services\Queue\QueueMessage;
use App\Services\Queue\QueueRedisConnection;
use App\Services\Queue\RedisQueueBackend;
use App\Services\Queue\SqlOutboxQueueBackend;
use App\Services\Queue\SqlQueueOutbox;
use App\Services\Settings;
use App\Services\TelegramJournal;
use App\Services\TelegramService;
use App\Test\Support\TelegramSqlSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once Settings::getAppRoot() . '/test/Support/TelegramSqlSchema.php';

/** SQLite by default; server runs require explicit OUTBOX_TEST_SQL_PORT, never application config. */
final class SqlQueueOutboxTest extends TestCase
{
    private Connection $sql;
    private ?Connection $admin = null;
    private string $database;
    private string $botId;
    private SqlQueueOutbox $outbox;
    private Settings $settings;

    protected function setUp(): void
    {
        $driver = getenv('OUTBOX_TEST_SQL_DRIVER') ?: 'pdo_sqlite';
        $params = ['driver' => $driver,
            'path' => 'file:outbox_test_' . bin2hex(random_bytes(12)) . '?mode=memory&cache=shared'];
        if ($driver !== 'pdo_sqlite') {
            $port = getenv('OUTBOX_TEST_SQL_PORT');
            if ($port === false) {
                self::markTestSkipped('Explicit isolated OUTBOX_TEST_SQL_PORT required');
            }
            $params = [
                'driver' => $driver, 'host' => '127.0.0.1', 'port' => (int) $port,
                'user' => getenv('OUTBOX_TEST_SQL_USER') ?: ($driver === 'pdo_mysql' ? 'root' : 'postgres'),
                'password' => getenv('OUTBOX_TEST_SQL_PASSWORD') ?: 'claire-test-only',
                'dbname' => $driver === 'pdo_mysql' ? 'mysql' : 'postgres',
            ];
            $this->admin = DriverManager::getConnection($params);
            $this->database = 'outbox_test_' . bin2hex(random_bytes(8));
            $this->admin->executeStatement('CREATE DATABASE ' . $this->database);
            $params['dbname'] = $this->database;
        }
        $this->sql = DriverManager::getConnection($params);
        TelegramSqlSchema::create($this->sql);
        $this->botId = 'outbox-bot-' . bin2hex(random_bytes(12));
        $this->settings = new Settings(['telegram' => ['bot_token' => $this->botId . ':secret'], 'queue' => [],
            'redis' => ['prefix' => 'outbox-test:']]);
        $this->outbox = new SqlQueueOutbox($this->sql, $this->settings);
    }

    protected function tearDown(): void
    {
        if (isset($this->sql)) {
            $this->sql->close();
        }
        if ($this->admin !== null) {
            $this->admin->executeStatement('DROP DATABASE ' . $this->database);
            $this->admin->close();
        }
    }

    public function testCanonicalEventIdentityAndStableStartIdentity(): void
    {
        $id = $this->enqueue();
        self::assertSame($id, $this->enqueue());
        self::assertSame(hash('sha256', json_encode([$this->botId, 'update:42'], JSON_THROW_ON_ERROR)),
            $this->row($id)['event_key']);
        $start = $this->outbox->enqueue(StartThreadJob::class, ['telegramUserId' => '7'], 'telegram');
        $row = $this->row($start);
        self::assertSame(hash('sha256', json_encode([$this->botId, 'job:' . $start], JSON_THROW_ON_ERROR)),
            $row['event_key']);
        self::assertSame($start, json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR)['generationId']);
        self::assertStringNotContainsString('secret', $row['payload']);
    }

    public function testTransactionCannotAcknowledgeUncommittedDispatch(): void
    {
        $this->sql->beginTransaction();
        try {
            $this->enqueue();
            self::fail('Must reject an outer transaction');
        } catch (RuntimeException) {
            $this->sql->rollBack();
        }
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM queue_outbox'));
    }

    public function testExternalTransactionsRejectExecutionBeforeCallback(): void
    {
        $id = $this->enqueue();
        $native = $this->sql->getNativeConnection();
        foreach ([false, true] as $nativeTransaction) {
            if ($nativeTransaction) {
                $native->beginTransaction();
            } else {
                $this->sql->beginTransaction();
            }
            try {
                $this->outbox->execute($this->job($id), static function (): void {
                    self::fail('Provider must not run inside an external transaction');
                });
                self::fail('Outbox must reject an external transaction');
            } catch (RuntimeException $error) {
                self::assertSame('Outbox requires an autocommit primary SQL connection', $error->getMessage());
            } finally {
                if ($nativeTransaction) {
                    $native->rollBack();
                } else {
                    $this->sql->rollBack();
                }
            }
            self::assertSame('pending', $this->row($id)['status']);
            self::assertSame(0, (int) $this->row($id)['attempts']);
        }
    }

    public function testExecutionFenceAndJournalCommitsAreVisibleBeforeDeliveryRollback(): void
    {
        $id = $this->enqueue();
        $event = $this->row($id)['event_key'];
        $observer = DriverManager::getConnection($this->sql->getParams());
        $journal = new TelegramJournal($this->sql);
        $deliveryFailure = new RuntimeException('Delivery transaction rolled back');
        try {
            try {
                $this->outbox->execute($this->job($id), function () use (
                    $id, $event, $observer, $journal, $deliveryFailure,
                ): void {
                    self::assertFalse($this->sql->isTransactionActive());
                    self::assertFalse($this->sql->getNativeConnection()->inTransaction());
                    $receipt = $observer->fetchAssociative('SELECT * FROM queue_outbox WHERE id = ?', [$id]);
                    self::assertIsArray($receipt);
                    self::assertSame('processing', $receipt['status']);
                    self::assertSame(1, (int) $receipt['attempts']);
                    self::assertSame('t-' . $id, $receipt['lease_token']);
                    self::assertGreaterThan(time(), (int) $receipt['lease_until']);

                    $record = ['botId' => $this->botId, 'updateId' => 'update:42',
                        'userId' => 'fixture-user', 'threadId' => 'fixture-thread', 'attempted' => true];
                    $journal->save($event, $record);
                    self::assertSame(1, (int) $observer->fetchOne(
                        'SELECT attempted FROM telegram_generation WHERE id = ?', [$event]));
                    self::assertFalse($this->sql->isTransactionActive());

                    $record['response'] = 'committed before delivery';
                    $journal->save($event, $record);
                    self::assertSame($record['response'], $observer->fetchOne(
                        'SELECT response FROM telegram_generation WHERE id = ?', [$event]));
                    $this->sql->beginTransaction();
                    try {
                        $this->sql->update('telegram_generation', ['attempted' => 0, 'response' => null],
                            ['id' => $event]);
                    } finally {
                        $this->sql->rollBack();
                    }
                    throw $deliveryFailure;
                });
                self::fail('Delivery must fail');
            } catch (RuntimeException $error) {
                self::assertSame($deliveryFailure, $error);
            }
            $saved = $observer->fetchAssociative('SELECT * FROM telegram_generation WHERE id = ?', [$event]);
            self::assertIsArray($saved);
            self::assertSame(1, (int) $saved['attempted']);
            self::assertSame('committed before delivery', $saved['response']);
            self::assertSame('pending', $observer->fetchOne('SELECT status FROM queue_outbox WHERE id = ?', [$id]));
            self::assertFalse($this->sql->isTransactionActive());

            $this->ready($id);
            $this->outbox->execute($this->job($id), static function (): void {});
            self::assertSame('completed', $observer->fetchOne('SELECT status FROM queue_outbox WHERE id = ?', [$id]));
            self::assertFalse($this->sql->getNativeConnection()->inTransaction());
        } finally {
            $observer->close();
        }
    }

    public function testSqlFailureNeverReachesRedisAndPropagates(): void
    {
        $transport = $this->createMock(QueueRedisConnection::class);
        $transport->expects(self::never())->method('evaluate');
        $backend = new SqlOutboxQueueBackend(new RedisQueueBackend($transport, $this->settings, $this->sql),
            $this->outbox);
        $this->sql->executeStatement('DROP TABLE queue_outbox');
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
    }

    public function testRedisFailureStillAcceptsDurableDispatch(): void
    {
        $transport = $this->createMock(QueueRedisConnection::class);
        $transport->expects(self::once())->method('evaluate')->willThrowException(new RuntimeException('secret credential'));
        $backend = new SqlOutboxQueueBackend(new RedisQueueBackend($transport, $this->settings, $this->sql),
            $this->outbox);
        $id = $backend->dispatch(TelegramService::class, $this->payload(), 'telegram');
        self::assertSame('pending', $this->row($id)['status']);
        self::assertSame('Redis publication failed', $this->row($id)['last_error']);
        self::assertSame(0, (int) $this->row($id)['attempts']);
    }

    public function testPublishBeforeSqlAcknowledgementCanBeRepeatedWithSameIdentity(): void
    {
        $id = $this->enqueue();
        $seen = [];
        $crash = function (string $published) use (&$seen): void {
            $seen[] = $published;
            throw new RuntimeException('Lost transport acknowledgement after publication');
        };
        self::assertSame('failed', $this->outbox->publish($id, $crash));
        $this->ready($id);
        self::assertSame('published', $this->outbox->publish($id, function (string $published) use (&$seen): void {
            $seen[] = $published;
        }));
        self::assertSame([$id, $id], $seen);
    }

    public function testProlongedBrokerOutageNeverConsumesExecutionBudget(): void
    {
        $id = $this->enqueue();
        for ($failure = 0; $failure < 20; $failure++) {
            $this->ready($id);
            $result = $this->outbox->publishPending('telegram', 1, static function (): void {
                throw new RuntimeException('Redis unavailable');
            });
            self::assertSame(1, $result['failed']);
            $row = $this->row($id);
            self::assertSame('pending', $row['status']);
            self::assertSame(0, (int) $row['attempts']);
            self::assertNotNull($row['payload']);
            self::assertGreaterThan(time(), (int) $row['available_at']);
            self::assertLessThanOrEqual(time() + 300, (int) $row['available_at']);
        }
        $this->ready($id);
        self::assertSame('published', $this->outbox->publish($id, static function (): void {}));
        $this->outbox->execute($this->job($id), static function (): void {});
        self::assertSame('completed', $this->row($id)['status']);
        self::assertSame(1, (int) $this->row($id)['attempts']);
    }

    public function testPostHandleLoggerFailureCommitsDeliveredReceiptAndNeverReplays(): void
    {
        $id = $this->enqueue();
        $calls = 0;
        $error = new RuntimeException('Post-handle logger failed');
        try {
            $this->outbox->execute($this->job($id), function () use ($id, &$calls, $error): void {
                $calls++;
                $this->journal($id, 'delivered response');
                $this->sql->update('telegram_generation', ['delivered' => 1],
                    ['id' => $this->row($id)['event_key']]);
                throw $error;
            });
            self::fail('Original logging error should propagate');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame('completed', $this->row($id)['status']);
        self::assertFalse($this->sql->isTransactionActive());
        self::assertNull($this->row($id)['payload']);
        self::assertSame($id, $this->enqueue());
        $this->outbox->transition($this->job($id), 'release');
        $this->outbox->execute($this->job($id), static function () use (&$calls): void { $calls++; });
        self::assertSame(1, $calls);
        self::assertSame('completed', $this->row($id)['status']);
    }

    public function testDeliveredJournalCompletesInterruptedReceiptWithoutPublicationOrHandle(): void
    {
        $id = $this->enqueue();
        $this->journal($id, 'delivered response');
        $this->sql->update('telegram_generation', ['delivered' => 1], ['id' => $this->row($id)['event_key']]);
        $this->sql->update('queue_outbox', ['status' => 'processing', 'attempts' => 5,
            'lease_token' => 'lost', 'lease_until' => 0], ['id' => $id]);
        self::assertSame('skipped', $this->outbox->publish($id, static function (): void {
            self::fail('Delivered journal must not be republished');
        }));
        $this->outbox->execute($this->job($id), static function (): void {
            self::fail('Delivered journal must not re-enter the handler');
        });
        self::assertSame('completed', $this->row($id)['status']);
        self::assertSame(5, (int) $this->row($id)['attempts']);
        self::assertNull($this->row($id)['payload']);
        self::assertSame($id, $this->enqueue());
    }

    public function testRedisLossRepublishesPublishedReceiptsWithBoundAndQueueFilter(): void
    {
        $id = $this->enqueue();
        $other = $this->outbox->enqueue(StartThreadJob::class, [], 'other');
        $this->outbox->publish($id, static function (): void {});
        $this->ready($id);
        $ids = [];
        $result = $this->outbox->publishPending('telegram', 1, function (string $id) use (&$ids): void {
            $ids[] = $id;
        });
        self::assertSame(['selected' => 1, 'published' => 1, 'failed' => 0, 'skipped' => 0], $result);
        self::assertSame([$id], $ids);
        self::assertSame('pending', $this->row($other)['status']);
        self::assertSame(0, $this->outbox->publishPending(null, 0, static function (): void {})['selected']);
    }

    public function testCompletionIsCommittedBeforeAckAndRedeliveryNeverExecutesAgain(): void
    {
        $job = $this->job($this->enqueue());
        $calls = 0;
        $operation = static function () use (&$calls): void { $calls++; };
        $this->outbox->execute($job, $operation);
        self::assertSame('completed', $this->row($job->id)['status']);
        self::assertNull($this->row($job->id)['payload']);
        $event = $this->row($job->id)['event_key'];
        self::assertSame($job->id, $this->enqueue());
        self::assertSame($event, $this->row($job->id)['event_key']);
        $this->outbox->execute($this->job($job->id), $operation);
        $this->outbox->transition($job, 'fail');
        $this->outbox->transition($job, 'defer');
        $this->outbox->transition($job, 'release');
        self::assertSame(1, $calls);
        self::assertSame('completed', $this->row($job->id)['status']);
        self::assertSame(1, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM queue_outbox'));
        self::assertSame('skipped', $this->outbox->publish($job->id, static function (): void {
            self::fail('Completed receipt must not publish');
        }));
    }

    public function testActiveSqlLeaseAndStaleTokenAreFenced(): void
    {
        $id = $this->enqueue();
        $this->sql->update('queue_outbox', ['status' => 'processing', 'lease_until' => time() + 100,
            'lease_token' => 'owner', 'attempts' => 1], ['id' => $id]);
        $job = $this->job($id);
        try {
            $this->outbox->execute($job, static function (): void { self::fail('Already claimed'); });
            self::fail('Must defer');
        } catch (ChatGenerationBusyException) {
            $this->outbox->transition($job, 'defer');
        }
        self::assertSame('owner', $this->row($id)['lease_token']);
        self::assertSame(1, (int) $this->row($id)['attempts']);
    }

    public function testAdvisoryLockPreventsExpiredReceiptPublicationDuringOperation(): void
    {
        $id = $this->enqueue();
        $this->outbox->execute($this->job($id), function () use ($id): void {
            self::assertFalse($this->sql->isTransactionActive());
            $this->sql->update('queue_outbox', ['lease_until' => 0], ['id' => $id]);
            $other = DriverManager::getConnection($this->sql->getParams());
            try {
                $outbox = new SqlQueueOutbox($other, $this->settings);
                self::assertSame('skipped', $outbox->publish($id, static function (): void {
                    self::fail('Active advisory lock must fence publication');
                }));
            } finally {
                $other->close();
            }
        });
        self::assertSame('completed', $this->row($id)['status']);
    }

    public function testBusyDoesNotConsumeBudgetOrEraseIntent(): void
    {
        $id = $this->enqueue();
        try {
            $this->outbox->execute($this->job($id), static function (): void {
                throw new ChatGenerationBusyException('busy');
            });
        } catch (ChatGenerationBusyException) {
            $this->outbox->transition($this->job($id), 'defer');
        }
        self::assertSame('pending', $this->row($id)['status']);
        self::assertSame(0, (int) $this->row($id)['attempts']);
        self::assertNotNull($this->row($id)['payload']);
        self::assertGreaterThan(time(), (int) $this->row($id)['available_at']);
    }

    public function testLockedExpiredReceiptCannotStarveLimitOnePublication(): void
    {
        $id = $this->enqueue();
        $next = $this->outbox->enqueue(TelegramService::class, ['update_json' => '{"update_id":43}'], 'telegram');
        $this->sql->update('queue_outbox', ['status' => 'processing', 'attempts' => 1,
            'available_at' => 0, 'lease_until' => 0, 'lease_token' => 'active-owner'], ['id' => $id]);
        $this->sql->update('queue_outbox', ['available_at' => 1], ['id' => $next]);
        $before = $this->row($id);
        $lock = new ChatThreadLock($this->sql->getNativeConnection(), 'outbox', $id);
        $other = DriverManager::getConnection($this->sql->getParams());
        try {
            $outbox = new SqlQueueOutbox($other, $this->settings);
            self::assertSame(['selected' => 1, 'published' => 0, 'failed' => 0, 'skipped' => 1],
                $outbox->publishPending('telegram', 1, static function (): void { self::fail('Locked receipt'); }));
            $after = $this->row($id);
            self::assertGreaterThan((new TelegramJournal($this->sql))->now(), (int) $after['available_at']);
            unset($before['available_at'], $before['updated_at'], $after['available_at'], $after['updated_at']);
            self::assertSame($before, $after);
            $published = [];
            self::assertSame(['selected' => 1, 'published' => 1, 'failed' => 0, 'skipped' => 0],
                $outbox->publishPending('telegram', 1, static function (string $id) use (&$published): void {
                    $published[] = $id;
                }));
            self::assertSame([$next], $published);
        } finally {
            $other->close();
            $lock->release();
        }
    }

    public function testInterruptedCommandWithoutJournalBecomesDead(): void
    {
        $id = $this->enqueue();
        $this->sql->update('queue_outbox', ['status' => 'processing', 'attempts' => 1,
            'lease_until' => 0, 'lease_token' => 'lost'], ['id' => $id]);
        self::assertSame('skipped', $this->outbox->publish($id, static function (): void {
            self::fail('An ambiguous /clear must never be replayed');
        }));
        self::assertSame('dead', $this->row($id)['status']);
    }

    public function testAttemptWithoutResponseBlocksButSavedResponseResumes(): void
    {
        $id = $this->enqueue();
        $this->journal($id);
        $this->sql->update('queue_outbox', ['status' => 'processing', 'attempts' => 1,
            'lease_until' => 0], ['id' => $id]);
        try {
            $this->outbox->execute($this->job($id), static function (): void { self::fail('Ambiguous agent'); });
            self::fail('Must reject ambiguity');
        } catch (NonRetryableJobException) {
            self::assertSame('dead', $this->row($id)['status']);
        }
        // A distinct event with a committed response can recover delivery after Redis loss.
        $payload = ['update_json' => '{"update_id":43}'];
        $next = $this->outbox->enqueue(TelegramService::class, $payload, 'telegram');
        $this->journal($next, 'committed response');
        $this->sql->update('queue_outbox', ['status' => 'processing', 'attempts' => 1,
            'lease_until' => 0], ['id' => $next]);
        self::assertSame('published', $this->outbox->publish($next, static function (): void {}));
        $this->outbox->execute(new QueueMessage($next, TelegramService::class, $payload, 'sql-outbox:telegram',
            ['token' => 'next', 'outbox_id' => $next]), static function (): void {});
        self::assertSame('completed', $this->row($next)['status']);
    }

    public function testUnmarkedStartIsRejectedWithoutCreatingReceipt(): void
    {
        $job = new QueueMessage('legacy-start', StartThreadJob::class, [], 'telegram', ['token' => 't']);
        try {
            $this->outbox->execute($job, static function (): void { self::fail('No stable handler identity'); });
            self::fail('Must reject ambiguous legacy start');
        } catch (NonRetryableJobException) {
            $this->outbox->transition($job, 'fail');
            self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM queue_outbox'));
        }
    }

    public function testRetryUsesSqlBudgetAndBoundedBackoffAcrossRedisLoss(): void
    {
        $id = $this->enqueue();
        $this->journal($id, 'durable response');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->ready($id);
            try {
                $this->outbox->execute($this->job($id), static function (): void {
                    throw new RuntimeException('delivery transport error containing secret');
                });
                self::fail('Delivery should fail');
            } catch (RuntimeException) {
                $this->outbox->transition($this->job($id), 'release');
            }
            $row = $this->row($id);
            self::assertSame($attempt, (int) $row['attempts']);
            self::assertSame($attempt === 5 ? 'dead' : 'pending', $row['status']);
            self::assertLessThanOrEqual(time() + 300, (int) $row['available_at']);
            self::assertStringNotContainsString('secret', $row['last_error']);
        }
        self::assertSame('skipped', $this->outbox->publish($id, static function (): void {
            self::fail('Attempt budget cannot be reset by Redis loss');
        }));
    }

    public function testUnjournaledFailureIsTerminalAndPayloadRemainsDurable(): void
    {
        $id = $this->enqueue();
        try {
            $this->outbox->execute($this->job($id), static function (): void {
                throw new RuntimeException('Ambiguous /clear transport error');
            });
            self::fail('Operation should fail');
        } catch (RuntimeException) {
            self::assertSame('dead', $this->row($id)['status']);
            self::assertNotNull($this->row($id)['payload']);
        }
    }

    public function testUnmarkedCommandIsRejectedWithoutAdoption(): void
    {
        $job = new QueueMessage('old-command', TelegramService::class, $this->payload(), 'telegram',
            ['token' => 'legacy-token', 'attempts' => '2']);
        try {
            $this->outbox->execute($job, static function (): void { self::fail('Legacy /clear is ambiguous'); });
            self::fail('Must reject ambiguous legacy reservation');
        } catch (NonRetryableJobException) {
            $this->outbox->transition($job, 'fail');
            self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM queue_outbox'));
        }
    }

    public function testBotsHaveSeparateEventIdentities(): void
    {
        $first = $this->enqueue();
        $other = new SqlQueueOutbox($this->sql,
            new Settings(['telegram' => ['bot_token' => $this->botId . '-other:another-secret'], 'queue' => []]));
        $second = $other->enqueue(TelegramService::class, $this->payload(), 'telegram');
        self::assertNotSame($first, $second);
        self::assertNotSame($this->row($first)['event_key'], $this->row($second)['event_key']);
    }

    public function testInvalidProtocolNeverResolvesAnEventToAnotherJobId(): void
    {
        $id = $this->enqueue();
        $jobs = [
            new QueueMessage($id, TelegramService::class, $this->payload(), 'telegram', ['token' => 't']),
            new QueueMessage('another-id', TelegramService::class, $this->payload(), 'telegram',
                ['token' => 't', 'outbox_id' => $id]),
            $this->job('missing-sql-id'),
            new QueueMessage($id, TelegramService::class, $this->payload(), 'wrong-queue',
                ['token' => 't', 'outbox_id' => $id]),
            new QueueMessage($id, TelegramService::class, ['update_json' => '{"update_id":99}'], 'sql-outbox:telegram',
                ['token' => 't', 'outbox_id' => $id]),
        ];
        foreach ($jobs as $job) {
            try {
                $this->outbox->execute($job, static function (): void { self::fail('Invalid SQL protocol'); });
                self::fail('Must reject mismatched protocol');
            } catch (NonRetryableJobException) {
                $this->outbox->transition($job, 'fail');
            }
            self::assertSame('pending', $this->row($id)['status']);
            self::assertSame(0, (int) $this->row($id)['attempts']);
            self::assertSame(1, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM queue_outbox'));
        }
    }

    public function testWebDelegatesWithoutAnySqlTable(): void
    {
        $this->sql->executeStatement('DROP TABLE queue_outbox');
        $transport = $this->createMock(QueueRedisConnection::class);
        $transport->expects(self::once())->method('evaluate')->willReturn('raw-web-id');
        $backend = new SqlOutboxQueueBackend(new RedisQueueBackend($transport, $this->settings, $this->sql),
            $this->outbox);
        self::assertSame('raw-web-id', $backend->dispatch('WebJob', [], 'web'));
    }

    private function enqueue(): string
    {
        return $this->outbox->enqueue(TelegramService::class, $this->payload(), 'telegram');
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['update_json' => '{"update_id":42,"message":{"text":"/clear"}}'];
    }

    private function job(string $id): QueueMessage
    {
        return new QueueMessage($id, TelegramService::class, $this->payload(), 'sql-outbox:telegram',
            ['token' => 't-' . $id, 'outbox_id' => $id]);
    }

    private function journal(string $id, ?string $response = null): void
    {
        $row = $this->row($id);
        $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
        $update = json_decode($payload['update_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->sql->insert('telegram_generation', [
            'id' => $row['event_key'], 'bot_id' => $this->botId, 'update_id' => 'update:' . $update['update_id'],
            'user_id' => 'fixture-user', 'thread_id' => 'fixture-thread', 'attempted' => 1,
            'delivered' => 0, 'response' => $response, 'created_at' => time(), 'updated_at' => time(), 'revision' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        $row = $this->sql->fetchAssociative('SELECT * FROM queue_outbox WHERE id = ?', [$id]);
        self::assertIsArray($row);
        return $row;
    }

    private function ready(string $id): void
    {
        $this->sql->update('queue_outbox', ['available_at' => 0], ['id' => $id]);
    }
}
