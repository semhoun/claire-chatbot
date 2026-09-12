<?php

declare(strict_types=1);

namespace App\Test\Integration;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\ChatThreadLock;
use App\Services\Session\InMemorySession;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Only substitutes the fixed table identifier; all SQL and PDO behavior remain real. */
final class IsolatedChatTestPdo extends PDO
{
    public function __construct(string $dsn, string $user, string $password, private string $table)
    {
        parent::__construct($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare((string) preg_replace('/\bchat_history\b/', $this->table, $query), $options);
    }
}

final class ChatDatabaseTest extends TestCase
{
    private ?PDO $first = null;
    private ?PDO $second = null;
    private string $table = '';

    /** @return array<string, array{string}> */
    public static function databases(): array
    {
        return ['mysql' => ['MYSQL'], 'postgresql' => ['PGSQL']];
    }

    private function connect(string $database): void
    {
        $prefix = 'CLAIRE_CHAT_TEST_' . $database;
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Requires explicit ' . $prefix . '_DSN');
        }
        $this->table = 'claire_chat_test_' . bin2hex(random_bytes(12));
        $user = getenv($prefix . '_USER') ?: '';
        $password = getenv($prefix . '_PASSWORD') ?: '';
        $this->first = new IsolatedChatTestPdo($dsn, $user, $password, $this->table);
        $this->second = new IsolatedChatTestPdo($dsn, $user, $password, $this->table);
        $suffix = $database === 'MYSQL' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci' : '';
        $this->first->exec('CREATE TABLE ' . $this->table . ' ('
            . 'user_id VARCHAR(128) NOT NULL, thread_id VARCHAR(128) PRIMARY KEY,'
            . ' messages TEXT NOT NULL, display_messages TEXT NOT NULL,'
            . ' display_messages_count INTEGER NOT NULL DEFAULT 0, title TEXT NULL, summary TEXT NULL)' . $suffix);
    }

    protected function tearDown(): void
    {
        foreach ([$this->first, $this->second] as $pdo) {
            if ($pdo?->inTransaction()) {
                $pdo->rollBack();
            }
        }
        if ($this->first !== null && $this->table !== '') {
            $this->first->exec('DROP TABLE IF EXISTS ' . $this->table);
        }
        $this->first = $this->second = null;
    }

    #[DataProvider('databases')]
    public function testLockSurvivesCommitAndExcludesOtherConnections(string $database): void
    {
        $this->connect($database);
        $lock = new ChatThreadLock($this->first, 'alice', $this->table);
        $this->first->beginTransaction();
        $this->first->commit();
        $otherUser = new ChatThreadLock($this->second, 'bob', $this->table);
        try {
            new ChatThreadLock($this->second, 'alice', $this->table);
            self::fail('Concurrent same-user/thread lock accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('Chat thread is busy', $exception->getMessage());
        }
        $lock->release();
        $next = new ChatThreadLock($this->second, 'alice', $this->table);
        $next->release();
        $otherUser->release();
    }

    #[DataProvider('databases')]
    public function testStaleWriterCannotResurrectRemovedExchange(string $database): void
    {
        $this->connect($database);
        $session = new InMemorySession([Auth::USERID => 'alice']);
        $first = new UserChatHistory($session, $this->first, threadId: 'thread');
        $first->addMessage(new UserMessage('Question'));
        $first->addMessage(new AssistantMessage('Answer'));
        $stale = new UserChatHistory($session, $this->second, threadId: 'thread');
        self::assertSame('Question', $first->removeLastExchange());
        try {
            $stale->addMessage(new UserMessage('Late write'));
            self::fail('Stale writer accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('refusing stale snapshot', $exception->getMessage());
        }
        $fresh = new UserChatHistory($session, $this->second, threadId: 'thread');
        self::assertSame([], $fresh->getMessages());
        self::assertSame([], $fresh->getDisplayMessages());
    }

    #[DataProvider('databases')]
    public function testKilledHelperDoesNotCloseInheritedParentConnectionOrReleaseItsLock(string $database): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            self::markTestSkipped('Requires pcntl and posix');
        }
        $this->connect($database);
        $lock = new ChatThreadLock($this->first, 'alice', $this->table);
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Cannot fork test helper');
        }
        if ($pid === 0) {
            // Match the queue heartbeat: never destroy inherited PDO or lock objects.
            posix_kill(getmypid(), SIGKILL);
            exit(1);
        }
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        self::assertSame(1, (int) $this->first->query('SELECT 1')->fetchColumn());
        try {
            new ChatThreadLock($this->second, 'alice', $this->table);
            self::fail('Helper termination released the parent lock');
        } catch (\RuntimeException $exception) {
            self::assertSame('Chat thread is busy', $exception->getMessage());
        }
        $lock->release();
        $next = new ChatThreadLock($this->second, 'alice', $this->table);
        $next->release();
    }

    #[DataProvider('databases')]
    public function testNoOpSaveSucceedsButDeletedRowIsRejected(string $database): void
    {
        $this->connect($database);
        $session = new InMemorySession([Auth::USERID => 'alice']);
        $history = new UserChatHistory($session, $this->first, threadId: 'thread');
        $history->replaceMessages([]);
        $history->flushAll();
        self::assertSame([], $history->getMessages());
        $this->second->exec('DELETE FROM ' . $this->table);
        $this->expectExceptionMessage('refusing stale snapshot');
        $history->replaceMessages([]);
    }

    #[DataProvider('databases')]
    public function testAssistantIdentityRoundTripsWithToolsAndRejectsStaleMetadataWrites(string $database): void
    {
        $this->connect($database);
        $session = new InMemorySession([Auth::USERID => 'alice']);
        $history = new UserChatHistory($session, $this->first, threadId: 'thread');
        $tool = new \NeuronAI\Tools\Tool('lookup')->setCallId('call-1')->setResult('Tool result');
        $history->addMessage(new UserMessage('Question'));
        $history->addMessage(new \NeuronAI\Chat\Messages\ToolCallMessage(null, [$tool]));
        $history->addMessage(new \NeuronAI\Chat\Messages\ToolResultMessage([$tool]));
        $history->addMessage(new AssistantMessage('Answer'));
        $stale = new UserChatHistory($session, $this->second, threadId: 'thread');
        $llmBefore = $this->first->query('SELECT messages FROM ' . $this->table)->fetchColumn();
        $history->identifyLastAssistantMessage('assistant-message-persistent');
        $history->identifyLastAssistantMessage('assistant-message-persistent');
        self::assertSame($llmBefore, $this->first->query('SELECT messages FROM ' . $this->table)->fetchColumn());
        $fresh = new UserChatHistory($session, $this->second, threadId: 'thread', createIfMissing: false);
        $formatted = $fresh->getFormattedMessages();
        self::assertSame('assistant-message-persistent', $formatted[1]['id']);
        self::assertSame('Tool result', $formatted[1]['toolsCall'][0]['result']);
        $this->expectExceptionMessage('refusing stale snapshot');
        $stale->identifyLastAssistantMessage('assistant-message-stale');
    }

    #[DataProvider('databases')]
    public function testCaseOnlyConcurrentEditIsNotHiddenByDatabaseCollation(string $database): void
    {
        $this->connect($database);
        $session = new InMemorySession([Auth::USERID => 'alice']);
        $first = new UserChatHistory($session, $this->first, threadId: 'thread');
        $first->addMessage(new UserMessage('HELLO'));
        $stale = new UserChatHistory($session, $this->second, threadId: 'thread');
        $this->first->exec('UPDATE ' . $this->table
            . " SET messages = REPLACE(messages, 'HELLO', 'hello'),"
            . " display_messages = REPLACE(display_messages, 'HELLO', 'hello')");
        $this->expectExceptionMessage('refusing stale snapshot');
        $stale->addMessage(new AssistantMessage('Late answer'));
    }

    public function testPostgresVersionDetectsDeleteAndRecreateWithIdenticalContent(): void
    {
        $this->connect('PGSQL');
        $session = new InMemorySession([Auth::USERID => 'alice']);
        $stale = new UserChatHistory($session, $this->first, threadId: 'thread');
        $this->second->exec('DELETE FROM ' . $this->table);
        new UserChatHistory($session, $this->second, threadId: 'thread');
        $this->expectExceptionMessage('refusing stale snapshot');
        $stale->replaceMessages([]);
    }

    public function testMysqlNoOpValidationDoesNotTrustAnOldRepeatableReadSnapshot(): void
    {
        $this->connect('MYSQL');
        $session = new InMemorySession([Auth::USERID => 'alice']);
        new UserChatHistory($session, $this->first, threadId: 'thread');
        $this->first->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->first->beginTransaction();
        $stale = new UserChatHistory($session, $this->first, threadId: 'thread');
        $this->second->exec('DELETE FROM ' . $this->table);
        // A normal SELECT still sees the deleted row in this transaction.
        self::assertSame(1, (int) $this->first->query('SELECT COUNT(*) FROM ' . $this->table)->fetchColumn());
        try {
            $stale->replaceMessages([]);
            self::fail('Old transaction snapshot accepted after concurrent deletion');
        } catch (\PDOException $exception) {
            // MariaDB may reject the UPDATE itself before our no-op validation.
            self::assertSame(1020, $exception->errorInfo[1]);
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('refusing stale snapshot', $exception->getMessage());
        }
    }
}
