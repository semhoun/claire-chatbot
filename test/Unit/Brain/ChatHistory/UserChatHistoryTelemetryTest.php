<?php

declare(strict_types=1);

namespace Test\Unit\Brain\ChatHistory;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\Observability\SqlInstrumentation;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\Messages\UserMessage;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserChatHistoryTelemetryTest extends TestCase
{
    private InMemoryExporter $inMemoryExporter;

    private ScopeInterface $scope;

    private PDO $pdo;

    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->inMemoryExporter = new InMemoryExporter();
        $this->scope = Configurator::create()->withTracerProvider(
            new TracerProvider(new SimpleSpanProcessor($this->inMemoryExporter))
        )->activate();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY,
            messages TEXT, display_messages TEXT, display_messages_count INTEGER,
            stored_messages TEXT, revision INTEGER, current_turn_id TEXT, title TEXT, summary TEXT)');
        $this->pdo->exec('CREATE TABLE chat_turn (id TEXT, user_id TEXT, thread_id TEXT, status TEXT,
            history_revision INTEGER)');
        $this->session = $this->createStub(SessionInterface::class);
        $this->session->method('get')->willReturnCallback(static fn ($key): ?string => $key === Auth::USERID ? 'private-owner' : null);
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    public function testReadInsertUpdateAndTurnValidationUseOriginalPdoAndExcludeBoundData(): void
    {
        $history = $this->history();
        $history->setThreadId('private-thread');
        $history->addMessage(new UserMessage('private-content'));
        $history->addMessage(new UserMessage('private-content-two'));
        self::assertSame(['SELECT', 'INSERT', 'UPDATE'], array_map(
            static fn ($span) => $span->getName(), $this->inMemoryExporter->getSpans()
        ));
        $this->pdo->beginTransaction();
        try {
            $history->persistStoppedTurn([], 'assistant-valid', 'private-turn');
            self::fail('Missing stopped turn should be rejected');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('Stopped history terminal identity mismatch', $runtimeException->getMessage());
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }

        self::assertCount(4, $this->inMemoryExporter->getSpans());
        foreach ($this->inMemoryExporter->getSpans() as $span) {
            self::assertSame('sqlite', $span->getAttributes()->get('db.system.name'));
            self::assertStringNotContainsString('private-', json_encode($span->getAttributes()->toArray(), JSON_THROW_ON_ERROR));
            self::assertSame([], $span->getEvents());
        }

        self::assertSame(2, (int) $this->pdo->query('SELECT revision FROM chat_history')->fetchColumn());
    }

    public function testConflictRetainsPdoExceptionAndRollbackStillWorks(): void
    {
        $userChatHistory = $this->history();
        $second = $this->history();
        $userChatHistory->setThreadId('thread');
        $second->setThreadId('thread');
        $userChatHistory->addMessage(new UserMessage('first'));
        $this->pdo->beginTransaction();
        try {
            $second->addMessage(new UserMessage('secret-conflict'));
            self::fail('Expected conflict');
        } catch (\RuntimeException $runtimeException) {
            self::assertInstanceOf(\PDOException::class, $runtimeException->getPrevious());
            $spans = $this->inMemoryExporter->getSpans();
            $span = $spans[array_key_last($spans)];
            self::assertSame(\PDOException::class, $span->getAttributes()->get('error.type'));
            self::assertSame([], $span->getEvents());
            self::assertStringNotContainsString('secret-conflict', json_encode($span->getAttributes()->toArray(), JSON_THROW_ON_ERROR));
        } finally {
            $this->pdo->rollBack();
        }

        self::assertSame(1, (int) $this->pdo->query('SELECT revision FROM chat_history')->fetchColumn());
    }

    public function testFacadeLoadsOnlyObservabilitySettingsWithoutChangingPublicConstructor(): void
    {
        $enabled = getenv('SQL_OBSERVABILITY_ENABLED');
        $limit = getenv('SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES');
        putenv('SQL_OBSERVABILITY_ENABLED=true');
        putenv('SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES=12');
        try {
            new UserChatHistory($this->session, $this->pdo, threadId: 'thread');
            [$span] = $this->inMemoryExporter->getSpans();
            self::assertSame('SELECT messa', $span->getAttributes()->get('db.query.text'));
            self::assertTrue($span->getAttributes()->get('claire.sql.query.truncated'));
        } finally {
            putenv($enabled === false ? 'SQL_OBSERVABILITY_ENABLED' : 'SQL_OBSERVABILITY_ENABLED=' . $enabled);
            putenv($limit === false ? 'SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES' : 'SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES=' . $limit);
        }
    }

    private function history(): UserChatHistory
    {
        $userChatHistory = new UserChatHistory($this->session, $this->pdo);
        // Enable only this facade; do not load deployment configuration or alter process environment.
        new \ReflectionProperty(UserChatHistory::class, 'sqlInstrumentation')
            ->setValue($userChatHistory, new SqlInstrumentation(true));
        return $userChatHistory;
    }
}
