<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatGenerationState;
use App\Services\ChatStopRequests;
use App\Services\ChatTurnJournal;
use App\Services\RedisClient;
use App\Services\Settings;
use App\Services\TelegramGeneration;
use App\Services\TelegramJournal;
use App\Test\Support\ChatTurnSqlSchema;
use App\Test\Support\TelegramSqlSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260930000100;
use Migrations\Version20260930000300;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class TelegramStopCommitConnection extends Connection
{
    public ?\Closure $beforeTransaction = null;
    public bool $loseAcknowledgement = false;

    public function transactional(\Closure $func): mixed
    {
        $before = $this->beforeTransaction;
        $this->beforeTransaction = null;
        if ($before !== null) {
            $before();
        }
        $result = parent::transactional($func);
        if ($this->loseAcknowledgement
            && $this->fetchOne('SELECT response FROM telegram_generation WHERE response IS NOT NULL') !== false) {
            $this->loseAcknowledgement = false;
            throw new RuntimeException('Terminal commit acknowledgement lost');
        }
        return $result;
    }
}

final class TelegramGenerationStopTest extends TestCase
{
    private TelegramStopCommitConnection $sql;
    private ChatStopRequests $stops;
    private TelegramGeneration $generation;
    private TelegramJournal $journal;
    private string $id;
    private array $state = [];

    protected function setUp(): void
    {
        require_once Settings::getAppRoot() . '/test/Support/TelegramSqlSchema.php';
        require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
        $this->sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true,
            'wrapperClass' => TelegramStopCommitConnection::class]);
        TelegramSqlSchema::create($this->sql);
        ChatTurnSqlSchema::create($this->sql);
        foreach ([Version20260930000100::class, Version20260930000300::class] as $class) {
            $migration = new $class($this->sql, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->sql->executeStatement($query->getStatement());
            }
        }
        $this->stops = new ChatStopRequests($this->sql);
        $this->journal = new TelegramJournal($this->sql);
        $this->id = TelegramJournal::id('123', 'update:42');
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturnCallback(fn (): array => $this->state);
        $redis->method('hset')->willReturnCallback(function (string $key, array $values): int {
            $this->state = array_replace($this->state, $values);
            return 1;
        });
        $settings = new Settings(['telegram' => ['bot_token' => '123:secret'], 'redis' => ['prefix' => 'test:'],
            'llm' => ['stop' => ['enabled' => true]]]);
        $this->generation = new TelegramGeneration($settings, $this->sql, new ChatGenerationState($redis, $settings));
    }

    protected function tearDown(): void
    {
        $this->sql->close();
    }

    public function testQueuedStopSkipsTranscriptionAndInferenceAndPreservesAvailableUserText(): void
    {
        $this->accept();
        $this->requestStop();
        $preserved = $sent = 0;
        $this->generation->run('user', 'new-session-thread', 'update:42',
            static function (): string { self::fail('No inference for a pre-requested stop'); },
            function (string $response, callable $checkpoint, bool $stopped) use (&$sent): void {
                self::assertSame('', $response);
                self::assertTrue($stopped);
                self::assertSame('stopped', $this->turn()['status']);
                $checkpoint('text:0', static function () use (&$sent): void { ++$sent; });
                $checkpoint('voice:0', static function (): void { self::fail('No stopped audio'); });
            },
            notifyFailure: static function (): void { self::fail('Stop is not a failure'); },
            prepare: static function (): string { self::fail('No transcription for queued stop'); },
            preserveStoppedInput: function (string $thread, mixed $prepared) use (&$preserved): void {
                ++$preserved;
                self::assertSame('original-thread', $thread);
                self::assertNull($prepared);
                $this->sql->executeStatement("UPDATE chat_history SET messages = '[\"accepted question\"]'");
            });
        self::assertSame(1, $preserved);
        self::assertSame(1, $sent);
        self::assertSame('stopped', $this->state['status']);
        self::assertSame('["accepted question"]', $this->sql->fetchOne('SELECT messages FROM chat_history'));
        self::assertSame($this->id, $this->turn()['generationId']);
        self::assertTrue($this->journal->load($this->id)['stopped']);
        self::assertTrue($this->journal->load($this->id)['delivered']);
        $this->generation->run('user', 'another-thread', 'update:42',
            static function (): string { self::fail('No retry inference'); },
            static function (): void { self::fail('Already delivered'); });
    }

    public function testPartialStopResumesOnlyUnconfirmedDelivery(): void
    {
        $this->accept();
        $generated = $sent = $deliveryAttempts = 0;
        $generate = function (string $thread, callable $guard, mixed $prepared, ?callable $stop) use (&$generated): string {
            ++$generated;
            self::assertSame('original-thread', $thread);
            self::assertFalse($stop());
            $this->sql->executeStatement("UPDATE chat_history SET messages = '[\"user\",\"partial\"]'");
            $this->requestStop();
            self::assertTrue($stop());
            return 'partial';
        };
        $deliver = function (string $response, callable $checkpoint, bool $stopped) use (&$sent, &$deliveryAttempts): void {
            ++$deliveryAttempts;
            self::assertSame('partial', $response);
            self::assertTrue($stopped);
            $checkpoint('text:0', static function () use (&$sent): void { ++$sent; });
            if ($deliveryAttempts === 1) {
                throw new RuntimeException('Post-send delivery crash');
            }
        };
        try {
            $this->generation->run('user', 'wrong-thread', 'update:42', $generate, $deliver);
            self::fail('Expected delivery failure');
        } catch (RuntimeException $error) {
            self::assertSame('Post-send delivery crash', $error->getMessage());
        }
        $this->generation->run('user', 'wrong-thread', 'update:42', $generate, $deliver);
        self::assertSame(1, $generated);
        self::assertSame(1, $sent);
        self::assertSame(2, $deliveryAttempts);
        self::assertSame('stopped', $this->turn()['status']);
        self::assertSame('["user","partial"]', $this->sql->fetchOne('SELECT messages FROM chat_history'));
    }

    public function testStopCommittedAfterFinalPredicateStillSuppressesAudio(): void
    {
        $this->accept();
        $this->generation->run('user', 'thread', 'update:42',
            function (): string {
                // Runs after run()'s final predicate but before the history transaction begins.
                $this->sql->beforeTransaction = fn () => $this->requestStop();
                return 'complete text';
            },
            function (string $response, callable $checkpoint, bool $stopped, array $notification): void {
                self::assertSame('raw partial', $response);
                self::assertTrue($stopped);
                self::assertSame('987', $notification['chatId']);
                self::assertSame(0, $notification['messageThreadId']);
                self::assertTrue($this->journal->load($this->id)['stopped']);
                $checkpoint('voice:0', static function (): void { self::fail('No race-lost audio'); });
            }, onComplete: function (Connection $connection, string $id, string $status): ?string {
                self::assertSame($this->sql, $connection);
                self::assertTrue($connection->isTransactionActive());
                self::assertSame($this->id, $id);
                self::assertSame('stopped', $status);
                return 'raw partial';
            });
        self::assertSame('stopped', $this->turn()['status']);
    }

    public function testStopDuringPreparationPreservesPreparedTextWithoutEnteringAgent(): void
    {
        $this->accept();
        $this->generation->run('user', 'thread', 'update:42',
            static function (): string { self::fail('No agent after preparation stop'); },
            static function (string $response, callable $checkpoint, bool $stopped): void {
                self::assertTrue($stopped);
            },
            prepare: function (?callable $stop): string {
                self::assertFalse($stop());
                $this->requestStop();
                return 'already transcribed';
            },
            preserveStoppedInput: static function (string $thread, mixed $prepared): void {
                self::assertSame('already transcribed', $prepared);
            });
        self::assertSame('stopped', $this->turn()['status']);
    }

    public function testStoppedCommitAcknowledgementLossNeverRollsBackOrRegenerates(): void
    {
        $this->accept();
        $calls = 0;
        $this->generation->run('user', 'thread', 'update:42',
            function () use (&$calls): string {
                ++$calls;
                $this->requestStop();
                $this->sql->loseAcknowledgement = true;
                return 'committed partial';
            },
            static function (string $response, callable $checkpoint, bool $stopped): void {
                self::assertSame('committed partial', $response);
                self::assertTrue($stopped);
            }, notifyFailure: static function (): void { self::fail('No failure after committed stop'); });
        self::assertSame(1, $calls);
        self::assertSame('stopped', $this->turn()['status']);
    }

    public function testWelcomeAndUnregisteredCommandRemainNotStoppable(): void
    {
        foreach (['job:welcome', 'update:42'] as $generation) {
            $this->generation->run('user', 'thread-' . $generation, $generation,
                static function (string $thread, callable $guard, mixed $prepared, ?callable $stop): string {
                    self::assertNull($stop);
                    return 'welcome';
                }, static function (string $response, callable $checkpoint, bool $stopped): void {
                    self::assertFalse($stopped);
                    self::assertSame('welcome', $response);
                });
        }
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_stop_request'));
    }

    public function testProviderFailureIsNotDisguisedAsStoppedAndCannotReplay(): void
    {
        $this->accept();
        $calls = $notices = 0;
        $generate = function () use (&$calls): string {
            ++$calls;
            $this->requestStop();
            throw new RuntimeException('Provider failed, not a controlled partial result');
        };
        $notify = static function () use (&$notices): void { ++$notices; };
        try {
            $this->generation->run('user', 'thread', 'update:42', $generate,
                static function (): void { self::fail('No successful delivery'); }, notifyFailure: $notify);
            self::fail('Expected a nonretryable provider failure');
        } catch (\App\Services\Queue\NonRetryableJobException $error) {
            self::assertSame('Provider failed, not a controlled partial result', $error->getPrevious()->getMessage());
        }
        self::assertSame('rolled_back', $this->turn()['status']);
        self::assertSame('rolled_back', $this->stops->request('user', 'original-thread', 'telegram', $this->id)['status']);
        $this->generation->run('user', 'other', 'update:42', $generate,
            static function (): void { self::fail('No failed generation response'); }, notifyFailure: $notify);
        self::assertSame(1, $calls);
        self::assertSame(1, $notices);
    }

    public function testFrozenAcceptanceRejectsForeignOwnerAndDestination(): void
    {
        $this->accept();
        foreach ([['other-user', []], ['user', ['chatId' => 111]], ['user', ['messageThreadId' => 5]]] as [$user, $notice]) {
            try {
                $this->generation->run($user, 'thread', 'update:42',
                    static function (): string { self::fail('No foreign inference'); },
                    static function (): void { self::fail('No foreign delivery'); }, $notice);
                self::fail('Expected immutable acceptance mismatch');
            } catch (\App\Services\Queue\NonRetryableJobException) {
                self::assertNull($this->journal->load($this->id));
            }
        }
    }

    public function testRetryExhaustionOfQueuedStopDoesNotSendFailureOrInfer(): void
    {
        $this->accept();
        $this->requestStop();
        $notices = 0;
        $this->generation->fail('user', 'wrong-thread', 'update:42', [],
            static function (): void { self::fail('No failure notice'); },
            notifyStopped: static function () use (&$notices): void { ++$notices; });
        self::assertSame(1, $notices);
        self::assertSame('stopped', $this->turn()['status']);
        $this->generation->fail('user', 'wrong-thread', 'update:42', [],
            static function (): void { self::fail('No failure notice after stopped terminal'); });
        self::assertSame(1, $notices);
    }

    public function testStoppedMarkerRoundTripsWithoutChangingResponseOrDeliverySteps(): void
    {
        $record = ['botId' => '123', 'updateId' => 'update:42', 'userId' => 'user', 'threadId' => 'thread',
            'stopped' => true, 'response' => '', 'deliveries' => ['text:0' => ['status' => 'confirmed']]];
        $this->journal->save($this->id, $record);
        $loaded = $this->journal->load($this->id);
        self::assertTrue($loaded['stopped']);
        self::assertSame('', $loaded['response']);
        self::assertSame(['text:0' => ['status' => 'confirmed']], $loaded['deliveries']);
        $loaded['delivered'] = true;
        $this->journal->save($this->id, $loaded);
        self::assertTrue($this->journal->load($this->id)['stopped']);
    }

    public function testExhaustedQueuedStopRetriesOnlyItsFrozenNoticeAfterTransportFailure(): void
    {
        $this->accept();
        $this->sql->executeStatement('UPDATE telegram_stop_target SET topic_id = 7');
        $this->requestStop();
        $attempts = $preserved = 0;
        $notify = static function (array $notification, string $response) use (&$attempts): void {
            self::assertSame('987', $notification['chatId']);
            self::assertSame(7, $notification['messageThreadId']);
            self::assertSame('', $response);
            if (++$attempts === 1) {
                throw new RuntimeException('Transport acknowledgement lost');
            }
        };
        $preserve = static function () use (&$preserved): void { ++$preserved; };
        try {
            $this->generation->fail('user', 'wrong-thread', 'update:42', [],
                static function (): void { self::fail('No failure notice'); }, $preserve, $notify);
            self::fail('Expected transport failure');
        } catch (RuntimeException $error) {
            self::assertSame('Transport acknowledgement lost', $error->getMessage());
        }
        $record = $this->journal->load($this->id);
        self::assertTrue($record['stopped']);
        self::assertFalse($record['delivered']);
        self::assertSame('', $record['response']);
        self::assertSame('uncertain', $record['deliveries']['stopped-notice']['status']);
        self::assertSame('stopped', $this->turn()['status']);
        $next = TelegramJournal::id('123', 'update:43');
        $this->stops->register('user', 'next-thread', 'telegram', $next);
        $this->state = ['messageId' => $next, 'status' => 'running'];
        for ($retry = 0; $retry < 2; ++$retry) {
            $this->generation->fail('user', 'next-thread', 'update:42', ['chatId' => 'different-current-chat'],
                static function (): void { self::fail('No failure notice'); }, $preserve, $notify);
        }
        self::assertSame(2, $attempts);
        self::assertSame(1, $preserved);
        self::assertSame(['messageId' => $next, 'status' => 'running'], $this->state);
        self::assertFalse($this->stops->isRequested('user', 'next-thread', 'telegram', $next));
        self::assertSame(1, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_turn'));
        $record = $this->journal->load($this->id);
        self::assertTrue($record['delivered']);
        self::assertSame('confirmed', $record['deliveries']['stopped-notice']['status']);
        self::assertSame(2, $record['deliveries']['stopped-notice']['attempts']);
    }

    public function testStoppedNoticeAmbiguousRetriesAreBoundedWithoutClaimingDelivery(): void
    {
        $this->accept();
        $this->requestStop();
        $attempts = 0;
        for ($retry = 0; $retry < 5; ++$retry) {
            try {
                $this->generation->fail('user', 'wrong-thread', 'update:42', [],
                    static function (): void { self::fail('No failure notice'); },
                    notifyStopped: static function () use (&$attempts): void {
                        ++$attempts;
                        throw new RuntimeException('Uncertain send');
                    });
                self::fail('Expected uncertain or exhausted delivery');
            } catch (RuntimeException $error) {
                self::assertSame($retry < 3 ? 'Uncertain send' : 'Telegram stopped notice delivery exhausted',
                    $error->getMessage());
            }
        }
        self::assertSame(3, $attempts);
        $record = $this->journal->load($this->id);
        self::assertFalse($record['delivered']);
        self::assertTrue($record['stopped']);
        self::assertSame('uncertain', $record['deliveries']['stopped-notice']['status']);
        self::assertSame(3, $record['deliveries']['stopped-notice']['attempts']);
        self::assertSame('stopped', $this->turn()['status']);
    }

    public function testConfirmedStoppedNoticeIsNotResentAfterFinalDeliveryMarkerLoss(): void
    {
        $this->accept();
        $this->requestStop();
        $attempts = 0;
        $notify = static function () use (&$attempts): void { ++$attempts; };
        $this->generation->fail('user', 'thread', 'update:42', [], static function (): void {},
            notifyStopped: $notify);
        $this->sql->executeStatement('UPDATE telegram_generation SET delivered = 0');
        $this->generation->fail('user', 'thread', 'update:42', [], static function (): void {},
            notifyStopped: $notify);
        self::assertSame(1, $attempts);
        self::assertTrue($this->journal->load($this->id)['delivered']);
    }

    public function testAtomicHooksShareTerminalTransactionAndFailureRollsBackEverything(): void
    {
        $this->accept();
        $this->sql->executeStatement('CREATE TABLE semantic_test (id VARCHAR(128), status VARCHAR(16))');
        try {
            $this->generation->run('user', 'thread', 'update:42',
                function (): string {
                    $this->requestStop();
                    return 'partial';
                }, static function (): void { self::fail('Failed commit cannot deliver'); },
                onBegin: function (Connection $connection, string $id): void {
                    self::assertTrue($connection->isTransactionActive());
                    self::assertSame($this->id, $id);
                    self::assertTrue($this->journal->load($id)['attempted']);
                },
                onComplete: static function (Connection $connection, string $id, string $status): void {
                    self::assertTrue($connection->isTransactionActive());
                    self::assertSame('stopped', $status);
                    $connection->insert('semantic_test', ['id' => $id, 'status' => $status]);
                    throw new RuntimeException('History persistence failed');
                });
            self::fail('Expected nonretryable failed attempt');
        } catch (\App\Services\Queue\NonRetryableJobException $error) {
            self::assertSame('History persistence failed', $error->getPrevious()->getMessage());
        }
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM semantic_test'));
        self::assertSame('rolled_back', $this->turn()['status']);
        self::assertSame('rolled_back', $this->stops->request('user', 'original-thread', 'telegram', $this->id)['status']);
        self::assertArrayNotHasKey('response', $this->journal->load($this->id));
        self::assertFalse($this->journal->load($this->id)['stopped']);
    }

    public function testQueuedStopStillCommitsWhenRedisIsUnavailable(): void
    {
        $this->accept();
        $this->requestStop();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willThrowException(new RuntimeException('Redis unavailable'));
        $redis->method('hset')->willThrowException(new RuntimeException('Redis unavailable'));
        $settings = new Settings(['telegram' => ['bot_token' => '123:secret'], 'redis' => ['prefix' => 'test:'],
            'llm' => ['stop' => ['enabled' => true]]]);
        $generation = new TelegramGeneration($settings, $this->sql, new ChatGenerationState($redis, $settings));
        $generation->run('user', 'thread', 'update:42',
            static function (): string { self::fail('No inference'); },
            static function (string $response, callable $checkpoint, bool $stopped): void {
                self::assertTrue($stopped);
            });
        self::assertSame('stopped', $this->turn()['status']);
    }

    public function testStoppedProjectionWithExplicitDisposableRedis(): void
    {
        $port = (int) getenv('QUEUE_TEST_REDIS_PORT');
        if (! extension_loaded('redis') || $port !== 16389) {
            self::markTestSkipped('Requires explicitly configured disposable Redis on port 16389');
        }
        $redis = new RedisClient();
        $redis->connect('127.0.0.1', $port, 1.0);
        $settings = new Settings(['telegram' => ['bot_token' => '123:secret'],
            'redis' => ['prefix' => 'test:telegram-stop:' . bin2hex(random_bytes(8)) . ':'],
            'llm' => ['stop' => ['enabled' => true]]]);
        $state = new ChatGenerationState($redis, $settings);
        try {
            $this->accept();
            $this->requestStop();
            new TelegramGeneration($settings, $this->sql, $state)->run('user', 'thread', 'update:42',
                static function (): string { self::fail('No inference'); },
                static function (string $response, callable $checkpoint, bool $stopped): void {
                    self::assertTrue($stopped);
                });
            self::assertSame('stopped', $state->get('user', 'original-thread')['status']);
            self::assertSame($this->id, $state->get('user', 'original-thread')['messageId']);
        } finally {
            $redis->del($state->key('user', 'original-thread'));
            $redis->close();
        }
    }

    private function accept(): void
    {
        $this->sql->insert('telegram_stop_target', ['id' => $this->id, 'bot_id' => '123', 'update_id' => 42,
            'user_id' => 'user', 'thread_id' => 'original-thread', 'chat_id' => '987', 'topic_id' => 0]);
        $this->stops->register('user', 'original-thread', 'telegram', $this->id);
    }

    private function requestStop(): void
    {
        $this->stops->request('user', 'original-thread', 'telegram', $this->id);
    }

    private function turn(): array
    {
        return new ChatTurnJournal($this->sql)->get($this->id);
    }
}
