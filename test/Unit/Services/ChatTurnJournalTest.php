<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\ChatTurnJournal;
use App\Services\Session\InMemorySession;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260917000000;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class TurnCommitTestConnection extends Connection
{
    public bool $loseAcknowledgement = false;

    public function transactional(\Closure $func): mixed
    {
        $result = parent::transactional($func);
        if ($this->loseAcknowledgement) {
            $this->loseAcknowledgement = false;
            throw new RuntimeException('Commit acknowledgement lost');
        }
        return $result;
    }
}

final class ChatTurnJournalTest extends TestCase
{
    private Connection $sql;
    private ChatTurnJournal $journal;

    protected function setUp(): void
    {
        $driver = getenv('CLAIRE_TURN_SQL_DRIVER') ?: 'pdo_sqlite';
        $params = $driver === 'pdo_sqlite' ? ['memory' => true] : [
            'host' => '127.0.0.1', 'port' => (int) getenv('CLAIRE_TURN_SQL_PORT'),
            'user' => getenv('CLAIRE_TURN_SQL_USER') ?: ($driver === 'pdo_mysql' ? 'root' : 'postgres'),
            'password' => getenv('CLAIRE_TURN_SQL_PASSWORD') ?: 'claire-test-only',
            'dbname' => getenv('CLAIRE_TURN_SQL_DATABASE') ?: 'claire_test',
        ];
        $this->sql = DriverManager::getConnection([
            'driver' => $driver, 'wrapperClass' => TurnCommitTestConnection::class,
        ] + $params);
        $this->sql->executeStatement('CREATE TEMPORARY TABLE chat_history ('
            . 'user_id VARCHAR(128) NOT NULL, thread_id VARCHAR(128) PRIMARY KEY, messages TEXT NOT NULL,'
            . "display_messages TEXT NOT NULL, display_messages_count INTEGER NOT NULL DEFAULT 0,"
            . 'title TEXT, summary TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            . ($driver === 'pdo_mysql' ? ' ON UPDATE CURRENT_TIMESTAMP' : '') . ')');
        $migration = new Version20260917000000($this->sql, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->sql->executeStatement(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ',
                $query->getStatement()));
        }
        $this->sql->executeStatement('CREATE TEMPORARY TABLE fence (id VARCHAR(128) PRIMARY KEY, response TEXT)');
        $this->journal = new ChatTurnJournal($this->sql);
    }

    protected function tearDown(): void
    {
        $this->sql->close();
    }

    public function testSqlSubmissionUniquenessSurvivesTerminalResultsAcrossThreads(): void
    {
        foreach (['succeeded', 'rolled_back'] as $status) {
            $owner = 'owner-' . $status;
            $id = 'original-' . $status;
            $thread = 'thread-' . $status;
            $this->journal->begin($id, $owner, $thread, 'web', $id, 'submission');
            if ($status === 'succeeded') {
                $this->journal->succeed($id, $owner);
            } else {
                $this->journal->rollback($id, $owner);
            }
            foreach ([$thread, 'another-' . $thread] as $target) {
                try {
                    $this->journal->begin('duplicate-' . $target, $owner, $target, 'web', 'new-message', 'submission');
                    self::fail('SQL must reject a reused owner/submission identity');
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                    self::assertNull($this->journal->get('duplicate-' . $target));
                    self::assertSame($status, $this->journal->get($id)['status']);
                    self::assertNull($this->sql->fetchOne(
                        'SELECT current_turn_id FROM chat_history WHERE thread_id = ?', [$thread],
                    ));
                }
            }
            self::assertFalse($this->sql->fetchOne(
                'SELECT thread_id FROM chat_history WHERE thread_id = ?', ['another-' . $thread],
            ));
        }
        self::assertSame(2, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_turn'));
        $this->journal->begin('telegram-1', 'telegram-owner', 'tg-1', 'telegram', 'tg-1');
        $this->journal->begin('telegram-2', 'telegram-owner', 'tg-2', 'telegram', 'tg-2');
        self::assertSame(4, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_turn'));
    }

    public function testRollbackRestoresRawCheckpointAfterToolsAndContextRewrite(): void
    {
        $old = $this->history();
        $old->addMessage(new UserMessage('Previous question'));
        $old->addMessage(new AssistantMessage('Previous answer'));
        $this->sql->executeStatement("UPDATE chat_history SET title = '', summary = NULL,"
            . " updated_at = '2020-01-01 00:00:00'");
        $before = $this->row();
        $turn = $this->begin();
        self::assertTrue($turn['entered']);
        self::assertArrayNotHasKey('checkpoint', $turn);
        $writer = $this->history();
        $tool = new Tool('lookup')->setCallId('call-1')->setResult('Tool result');
        $writer->addMessage(new UserMessage('Failed question'));
        $writer->addMessage(new ToolCallMessage(null, [$tool]));
        $writer->addMessage(new ToolResultMessage([$tool]));
        $writer->replaceMessages([new UserMessage('Short term memory rewrite')]);
        $this->sql->executeStatement("UPDATE chat_history SET title = 'Partial', summary = 'Partial',"
            . " updated_at = '2030-01-01 00:00:00', revision = revision + 1");
        $revision = (int) $this->row()['revision'];
        self::assertSame('rolled_back', $this->journal->rollback('turn', 'alice')['status']);
        $after = $this->row();
        self::assertSame($revision + 1, (int) $after['revision']);
        unset($before['revision'], $after['revision']);
        self::assertSame($before, $after);
        self::assertNull($this->sql->fetchOne('SELECT checkpoint FROM chat_turn'));
        self::assertSame('Previous question', $this->history()->getMessages()[0]->getContent());
        $this->expectExceptionMessage('refusing stale snapshot');
        $writer->refresh();
    }

    public function testCheckpointPrecedesMutatingLoadAndFailureBeforeFirstPersistence(): void
    {
        $this->history()->replaceMessages([new AssistantMessage('Opening')]);
        $raw = $this->row()['messages'];
        $this->begin();
        $this->history(); // Loading an assistant-first context writes an opening user message.
        self::assertNotSame($raw, $this->row()['messages']);
        $this->journal->rollback('turn', 'alice');
        self::assertSame($raw, $this->row()['messages']);
        $this->begin('next');
        $this->journal->rollback('next', 'alice');
        self::assertSame($raw, $this->row()['messages']);
    }

    public function testOldWriterCannotReloadIntoNextTurnOrWriteAfterAba(): void
    {
        $old = $this->history();
        $this->begin();
        $active = $this->history();
        $this->journal->rollback('turn', 'alice');
        $this->begin('next');
        foreach ([$old, $active] as $writer) {
            foreach (['refresh', 'write'] as $action) {
                try {
                    $action === 'refresh' ? $writer->refresh() : $writer->replaceMessages([]);
                    self::fail('Obsolete writer was rearmed');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('refusing stale snapshot', $exception->getMessage());
                }
            }
        }
        self::assertSame('next', $this->row()['current_turn_id']);
    }

    public function testAtomicCallbacksRollbackTogetherAndAreNeverReplayed(): void
    {
        try {
            $this->begin(atomic: function (Connection $sql): void {
                $sql->insert('fence', ['id' => 'turn']);
                throw new RuntimeException('Fence failed');
            });
            self::fail('Callback failure swallowed');
        } catch (RuntimeException $exception) {
            self::assertSame('Fence failed', $exception->getMessage());
        }
        self::assertNull($this->journal->get('turn'));
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_history'));
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM fence'));
        $this->begin(atomic: static fn (Connection $sql) => $sql->insert('fence', ['id' => 'turn']));
        self::assertFalse($this->begin(atomic: static function (): void {
            self::fail('Begin fence replayed');
        })['entered']);
        try {
            $this->journal->succeed('turn', 'alice', static function (Connection $sql): void {
                $sql->update('fence', ['response' => 'answer'], ['id' => 'turn']);
                throw new RuntimeException('Response failed');
            });
            self::fail('Response failure swallowed');
        } catch (RuntimeException $exception) {
            self::assertSame('Response failed', $exception->getMessage());
        }
        self::assertSame('running', $this->journal->get('turn')['status']);
        self::assertSame('turn', $this->row()['current_turn_id']);
        self::assertNull($this->sql->fetchOne('SELECT response FROM fence'));
        $terminal = $this->journal->succeed('turn', 'alice', static fn (Connection $sql) =>
            $sql->update('fence', ['response' => 'answer'], ['id' => 'turn']));
        self::assertSame('answer', $this->sql->fetchOne('SELECT response FROM fence'));
        self::assertSame($terminal, $this->journal->succeed('turn', 'alice', static function (): void {
            self::fail('Success callback replayed');
        }));
        self::assertSame($terminal, $this->journal->rollback('turn', 'alice'));
        self::assertNull($this->sql->fetchOne('SELECT checkpoint FROM chat_turn'));
    }

    public function testOldTerminalResultNeverChangesNewTurn(): void
    {
        $this->begin();
        $terminal = $this->journal->rollback('turn', 'alice');
        $this->begin('next');
        $before = $this->row();
        self::assertSame($terminal, $this->journal->rollback('turn', 'alice'));
        self::assertSame($terminal, $this->journal->succeed('turn', 'alice'));
        self::assertFalse($this->begin()['entered']);
        self::assertSame($before, $this->row());
        self::assertSame(['next'], array_column($this->journal->running(1), 'id'));
    }

    public function testMissingHistoryRemainsRecoverableWithoutResurrection(): void
    {
        $this->begin();
        $this->sql->executeStatement('DELETE FROM chat_history');
        try {
            $this->journal->rollback('turn', 'alice');
            self::fail('Missing history accepted');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('recovery required', $exception->getMessage());
        }
        self::assertSame('running', $this->journal->get('turn')['status']);
        self::assertNotNull($this->sql->fetchOne('SELECT checkpoint FROM chat_turn'));
        $this->expectExceptionMessage('requires recovery');
        $this->begin('next');
    }

    public function testDeletionNeutralizationIsAtomicAndBlocksNewIds(): void
    {
        $this->begin();
        $writer = $this->history();
        try {
            $this->sql->transactional(function (): void {
                $this->journal->neutralize('alice', 'thread');
                $this->sql->executeStatement('DELETE FROM chat_history');
                throw new RuntimeException('Deletion failed');
            });
        } catch (RuntimeException) {
        }
        self::assertSame('running', $this->journal->get('turn')['status']);
        self::assertNotNull($this->row());
        $this->sql->transactional(function (): void {
            $this->journal->neutralize('alice', 'thread');
            $this->sql->executeStatement('DELETE FROM chat_history');
        });
        self::assertSame('rolled_back', $this->journal->rollback('turn', 'alice')['status']);
        self::assertNotNull($this->journal->get('turn')['deletedAt']);
        self::assertNull($this->sql->fetchOne('SELECT checkpoint FROM chat_turn'));
        try {
            $writer->refresh();
            self::fail('Deleted history recreated');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('refusing stale snapshot', $exception->getMessage());
        }
        $this->expectExceptionMessage('requires recovery');
        $this->begin('next');
    }

    public function testOwnerAndIdentityConflictsNeverModifyTheCheckpoint(): void
    {
        $this->begin();
        $before = $this->sql->fetchAssociative('SELECT * FROM chat_turn');
        $operations = [
            fn () => $this->journal->rollback('turn', 'bob'),
            fn () => $this->journal->succeed('turn', 'bob'),
            fn () => $this->journal->begin('turn', 'alice', 'other-thread', 'web', 'turn'),
            fn () => $this->journal->begin('turn', 'bob', 'thread', 'web', 'turn'),
            fn () => $this->begin('next'),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Conflict accepted');
            } catch (RuntimeException) {
                self::assertSame($before, $this->sql->fetchAssociative('SELECT * FROM chat_turn'));
            }
        }
    }

    public function testOuterDbalAndNativeTransactionsAreRejected(): void
    {
        foreach ([$this->sql, $this->sql->getNativeConnection()] as $connection) {
            $connection->beginTransaction();
            try {
                $this->begin();
                self::fail('Non-durable begin accepted');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('independent short commit', $exception->getMessage());
            } finally {
                $connection->rollBack();
            }
        }
        self::assertNull($this->journal->get('turn'));
    }

    public function testLostCommitAcknowledgementsAreResolvedBySqlWithoutReplay(): void
    {
        foreach (['begin', 'succeed'] as $operation) {
            $this->sql->loseAcknowledgement = true;
            try {
                $operation === 'begin' ? $this->begin() : $this->journal->succeed('turn', 'alice');
                self::fail('Lost acknowledgement was not simulated');
            } catch (RuntimeException $exception) {
                self::assertSame('Commit acknowledgement lost', $exception->getMessage());
            }
            $recovered = new ChatTurnJournal($this->sql)->get('turn');
            self::assertSame($operation === 'begin' ? 'running' : 'succeeded', $recovered['status']);
            self::assertFalse($this->begin()['entered']);
        }
        self::assertSame('succeeded', $this->journal->rollback('turn', 'alice')['status']);
        $this->begin('next');
        $this->sql->loseAcknowledgement = true;
        try {
            $this->journal->rollback('next', 'alice');
        } catch (RuntimeException $exception) {
            self::assertSame('Commit acknowledgement lost', $exception->getMessage());
        }
        self::assertSame('rolled_back', $this->journal->rollback('next', 'alice')['status']);
        self::assertSame([], $this->journal->running());
    }

    public function testInconsistentTurnOrRevisionLeavesCheckpointRecoverable(): void
    {
        $this->begin();
        $before = $this->sql->fetchAssociative('SELECT * FROM chat_turn');
        foreach ([['current_turn_id' => 'other'], ['current_turn_id' => 'turn', 'revision' => 0]] as $data) {
            $this->sql->update('chat_history', $data, ['thread_id' => 'thread']);
            try {
                $this->journal->rollback('turn', 'alice');
                self::fail('Inconsistent history restored');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('recovery required', $exception->getMessage());
            }
            self::assertSame($before, $this->sql->fetchAssociative('SELECT * FROM chat_turn'));
        }
    }

    public function testNotificationRejectsTokensAndPreservesDestinationIdentifiers(): void
    {
        try {
            $this->journal->begin('turn', 'alice', 'thread', 'telegram', 'generation',
                notification: ['token' => 'secret']);
            self::fail('Token persisted');
        } catch (\InvalidArgumentException) {
            self::assertNull($this->journal->get('turn'));
        }
        $notification = ['botId' => 'bot', 'chatId' => '-123', 'messageThreadId' => 42];
        $turn = $this->journal->begin('turn', 'alice', 'thread', 'telegram', 'generation',
            notification: $notification);
        self::assertSame($notification, $turn['notification']);
        self::assertSame('generation', $turn['generationId']);
        self::assertSame('alice', $turn['userId']);
    }

    private function begin(string $id = 'turn', ?callable $atomic = null): array
    {
        return $this->journal->begin($id, 'alice', 'thread', 'web', $id, atomic: $atomic);
    }

    private function history(): UserChatHistory
    {
        return new UserChatHistory(new InMemorySession([Auth::USERID => 'alice']),
            $this->sql->getNativeConnection(), threadId: 'thread');
    }

    private function row(): array
    {
        return $this->sql->fetchAssociative('SELECT * FROM chat_history');
    }
}
