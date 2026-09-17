<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Auth;
use App\Services\ChatGenerationState;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\ChatThreadLock;
use App\Services\ChatTurnJournal;
use App\Services\ChatTurnRecovery;
use App\Services\Queue\QueueMessage;
use App\Services\RedisClient;
use App\Services\Settings;
use App\Services\TelegramGeneration;
use App\Services\TelegramJournal;
use App\Services\TelegramService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatTurnRecoveryTest extends TestCase
{
    private Connection $sql;
    private ChatTurnJournal $journal;
    private ChatTurnRecovery $recovery;
    private array $states = [];
    private array $events = [];
    private bool $redisDown = false;
    private ChatGenerationState $state;
    private TelegramService $telegram;

    protected function setUp(): void
    {
        require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
        $this->sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        \App\Test\Support\ChatTurnSqlSchema::create($this->sql);
        \App\Test\Support\TelegramSqlSchema::create($this->sql);
        $this->journal = new ChatTurnJournal($this->sql);
        $settings = new Settings(['redis' => ['prefix' => 'recovery:'], 'telegram' => ['bot_token' => '123:secret']]);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturnCallback(function (string $key): array {
            if ($this->redisDown) {
                throw new \RuntimeException('Redis unavailable');
            }
            return $this->states[$key] ?? [];
        });
        $redis->method('hset')->willReturnCallback(function (string $key, array $values): int {
            $this->states[$key] = array_map(strval(...), $values);
            return 1;
        });
        $redis->method('publish')->willReturnCallback(function (string $key, string $message): int {
            $this->events[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            return 1;
        });
        $this->state = new ChatGenerationState($redis, $settings);
        $this->telegram = $this->createMock(TelegramService::class);
        $this->recovery = new ChatTurnRecovery($this->sql,
            new ChatStreamPublisher($redis, new ChatStreamSubscriber($settings), $settings),
            new TelegramGeneration($settings, $this->sql, $this->state), $this->telegram, $settings, new NullLogger());
    }

    public function testActiveLockIsNeverRecoveredAndAbandonedTurnRestoresCheckpoint(): void
    {
        $this->telegram->expects(self::never())->method('sendFailureNotice');
        $this->journal->begin('message-1', 'user', 'thread', 'web', 'message-1', 'submission-1',
            ['sessionId' => ChatStreamSubscriber::scope('user', 'tab')]);
        $this->sql->executeStatement("UPDATE chat_history SET messages = 'partial', title = 'partial'");
        $this->state->set('user', 'thread', 'message-1', 'running', true);
        $lock = new ChatThreadLock($this->sql->getNativeConnection(), 'user', 'thread');
        self::assertSame(['locked' => 1], $this->recovery->recover()['counts']);
        self::assertSame('running', $this->journal->get('message-1')['status']);
        $lock->release();
        self::assertSame(['rolled_back' => 1], $this->recovery->recover()['counts']);
        self::assertSame('[]', $this->sql->fetchOne('SELECT messages FROM chat_history'));
        self::assertNull($this->sql->fetchOne('SELECT title FROM chat_history'));
        self::assertSame('submission-1', $this->events[0]['payload']['submissionId']);
        self::assertTrue($this->events[0]['payload']['rollbackConfirmed']);
    }

    public function testRedisOutageDoesNotPreventRollbackAndProjectionCanResume(): void
    {
        $this->telegram->expects(self::never())->method('sendFailureNotice');
        $this->journal->begin('message-1', 'user', 'thread', 'web', 'message-1',
            notification: ['sessionId' => ChatStreamSubscriber::scope('user', 'tab')]);
        $this->state->set('user', 'thread', 'message-1', 'running', true);
        $this->redisDown = true;
        $this->recovery->recover();
        self::assertSame('rolled_back', $this->journal->get('message-1')['status']);
        self::assertSame([], $this->events);
        $this->redisDown = false;
        $this->recovery->recover();
        self::assertSame('error', $this->state->get('user', 'thread')['status']);
        self::assertCount(1, $this->events);
    }

    public function testSqlSuccessWinsAndOldTerminalTurnDoesNotOverwriteNewerGeneration(): void
    {
        $this->telegram->expects(self::never())->method('sendFailureNotice');
        $turn = $this->journal->begin('message-old', 'user', 'thread', 'web', 'message-old');
        $this->journal->succeed('message-old', 'user');
        $this->state->set('user', 'thread', 'message-old', 'running', true);
        $this->recovery->recoverTurn($turn);
        self::assertSame('done', $this->state->get('user', 'thread')['status']);
        $this->state->set('user', 'thread', 'message-new', 'running', true);
        $this->recovery->recoverTurn($turn);
        self::assertSame('message-new', $this->state->get('user', 'thread')['messageId']);
        self::assertSame('running', $this->state->get('user', 'thread')['status']);
        self::assertSame([], $this->events);
    }

    public function testTerminalPreEntryFailureDoesNotRemovePreviousExchange(): void
    {
        $this->telegram->expects(self::never())->method('sendFailureNotice');
        $this->journal->begin('message-old', 'user', 'thread', 'web', 'message-old');
        $this->sql->executeStatement("UPDATE chat_history SET messages = '[\"previous\"]'");
        $this->journal->succeed('message-old', 'user');
        $this->state->set('user', 'thread', 'message-new', 'queued', false);
        $job = new QueueMessage('job', \App\Job\Web\NewMessageJob::class, [
            'messageId' => 'message-new', 'threadId' => 'thread', 'submissionId' => 'submission-new',
            'sessionId' => 'tab', 'session' => [Auth::USERID => 'user'],
        ], 'test');
        $this->recovery->failed($job);
        $this->recovery->failed($job);
        self::assertSame('rolled_back', $this->journal->get('message-new')['status']);
        self::assertSame('["previous"]', $this->sql->fetchOne('SELECT messages FROM chat_history'));
        self::assertTrue($this->events[0]['payload']['rollbackConfirmed']);
    }

    public function testRevisionOrdersSameSecondTurnsAndRepairsKnownStaleProjections(): void
    {
        $this->telegram->expects(self::never())->method('sendFailureNotice');
        $this->journal->begin('z-old', 'user', 'thread', 'web', 'z-old');
        $old = $this->journal->succeed('z-old', 'user');
        $this->journal->begin('a-new', 'user', 'thread', 'web', 'a-new');
        $new = $this->journal->rollback('a-new', 'user');
        $this->sql->executeStatement('UPDATE chat_turn SET created_at = 1234');
        self::assertGreaterThan($old['historyRevision'], $new['historyRevision']);
        $this->recovery->recoverTurn($old);
        self::assertSame([], $this->state->get('user', 'thread'));
        $this->recovery->recoverTurn($new);
        self::assertSame('a-new', $this->state->get('user', 'thread')['messageId']);
        foreach (['done', 'running', 'queued'] as $status) {
            $this->state->set('user', 'thread', 'z-old', $status, true);
            $this->recovery->recoverTurn($new);
            self::assertSame('a-new', $this->state->get('user', 'thread')['messageId']);
            self::assertSame('error', $this->state->get('user', 'thread')['status']);
        }
        foreach (['queued', 'running'] as $status) {
            $this->state->set('user', 'thread', 'not-entered-yet', $status, false);
            $this->recovery->recoverTurn($new);
            self::assertSame('not-entered-yet', $this->state->get('user', 'thread')['messageId']);
            self::assertSame($status, $this->state->get('user', 'thread')['status']);
        }
        $active = $this->journal->begin('active', 'user', 'thread', 'web', 'active');
        $this->state->set('user', 'thread', 'active', 'running', true);
        $lock = new ChatThreadLock($this->sql->getNativeConnection(), 'user', 'thread');
        try {
            self::assertSame(['locked' => 3], $this->recovery->recover()['counts']);
            self::assertSame('running', $this->journal->get($active['id'])['status']);
        } finally {
            $lock->release();
        }
    }

    public function testTelegramSessionLockAndBoundedFailureNoticesWithoutResponse(): void
    {
        $id = TelegramJournal::id('123', 'update:1');
        $journal = new TelegramJournal($this->sql);
        $record = ['userId' => 'user', 'threadId' => 'thread', 'botId' => '123', 'updateId' => 'update:1', 'attempted' => true];
        $this->journal->begin($id, 'user', 'thread', 'telegram', 'update:1',
            notification: ['sessionId' => '42', 'chatId' => 84],
            atomic: function () use ($journal, $id, &$record): void { $journal->saveInTransaction($id, $record); });
        $lock = new ChatThreadLock($this->sql->getNativeConnection(), 'telegram-session', '42');
        self::assertSame(['locked' => 1], $this->recovery->recover()['counts']);
        $lock->release();
        $this->telegram->expects(self::exactly(3))->method('sendFailureNotice')->with(84)
            ->willThrowException(new \RuntimeException('Lost Telegram acknowledgement'));
        $this->redisDown = true;
        for ($i = 0; $i < 5; $i++) {
            $this->recovery->recover();
        }
        $record = $journal->load($id);
        self::assertArrayNotHasKey('response', $record);
        self::assertSame(3, $record['deliveries']['failure-notice']['attempts']);
        self::assertSame('uncertain', $record['deliveries']['failure-notice']['status']);
        self::assertSame('rolled_back', $this->journal->get($id)['status']);
        self::assertSame('[]', $this->sql->fetchOne('SELECT messages FROM chat_history'));
        self::assertSame('[]', $this->sql->fetchOne('SELECT display_messages FROM chat_history'));
    }

    public function testIdleWorkerRunsRecoveryAtStartupAndPeriodically(): void
    {
        $this->telegram->expects(self::never())->method('sendFailureNotice');
        $this->journal->begin('message-1', 'user', 'thread', 'web', 'message-1');
        $container = $this->createStub(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => $id === ChatTurnRecovery::class);
        $container->method('get')->willReturn($this->recovery);
        $backend = $this->createMock(\App\Services\Queue\QueueBackendInterface::class);
        $worker = new \App\Services\Queue\QueueWorker($backend, $container, new NullLogger());
        $calls = 0;
        $backend->expects(self::exactly(2))->method('reserveNextAvailable')->willReturnCallback(function () use ($worker, &$calls): null {
            if (++$calls === 1) {
                self::assertSame('rolled_back', $this->journal->get('message-1')['status']);
                $this->journal->begin('message-2', 'user', 'thread', 'web', 'message-2');
                new \ReflectionProperty($worker, 'lastRecoveryAt')->setValue($worker, 0);
            } else {
                self::assertSame('rolled_back', $this->journal->get('message-2')['status']);
                $worker->requestStop();
            }
            return null;
        });
        self::assertSame(0, $worker->run(new \App\Services\Queue\QueueWorkerOptions('test', 1, 0, 10), 'worker'));
    }

    public function testMaintenanceRecoveryIsDryRunUnlessApplyAndSupportsBoundedPages(): void
    {
        $this->telegram->expects(self::never())->method('sendFailureNotice');
        $this->journal->begin('message-1', 'user', 'thread-1', 'web', 'message-1');
        $this->journal->begin('message-2', 'user', 'thread-2', 'web', 'message-2');
        $container = $this->createStub(\Psr\Container\ContainerInterface::class);
        $container->method('get')->willReturn($this->recovery);
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \App\Console\ChatMaintenanceCommand($container));
        self::assertSame(0, $tester->execute(['--recover' => true, '--limit' => '1']));
        self::assertSame('running', $this->journal->get('message-1')['status']);
        self::assertSame(0, $tester->execute(['--recover' => true, '--limit' => '1', '--apply' => true]));
        self::assertSame('rolled_back', $this->journal->get('message-1')['status']);
        self::assertSame('running', $this->journal->get('message-2')['status']);
        $cursor = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['cursor'];
        self::assertSame(0, $tester->execute(['--recover' => true, '--limit' => '1', '--apply' => true, '--cursor' => $cursor]));
        self::assertSame('rolled_back', $this->journal->get('message-2')['status']);
    }
}
