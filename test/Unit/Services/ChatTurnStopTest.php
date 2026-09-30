<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\GenerationStopHistoryTrimmer;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\ChatHistory\WorkingChatHistory;
use App\Services\Auth;
use App\Services\ChatStopRequests;
use App\Services\ChatTurnJournal;
use App\Services\Session\InMemorySession;
use App\Test\Support\ChatTurnSqlSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260930000100;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class StopAcknowledgementConnection extends Connection
{
    public bool $loseAcknowledgement = false;

    public function commit(): void
    {
        parent::commit();
        if ($this->loseAcknowledgement) {
            $this->loseAcknowledgement = false;
            throw new RuntimeException('Stopped commit acknowledgement lost');
        }
    }
}

final class ChatTurnStopTest extends TestCase
{
    private Connection $sql;
    private ChatStopRequests $stops;
    private ChatTurnJournal $journal;

    protected function setUp(): void
    {
        $driver = getenv('CLAIRE_TURN_SQL_DRIVER') ?: 'pdo_sqlite';
        $this->sql = DriverManager::getConnection(($driver === 'pdo_sqlite'
            ? ['driver' => $driver, 'memory' => true]
            : ['driver' => $driver, 'host' => '127.0.0.1', 'port' => (int) getenv('CLAIRE_TURN_SQL_PORT'),
                'user' => getenv('CLAIRE_TURN_SQL_USER'), 'password' => getenv('CLAIRE_TURN_SQL_PASSWORD'),
                'dbname' => getenv('CLAIRE_TURN_SQL_DATABASE')]) + ['wrapperClass' => StopAcknowledgementConnection::class]);
        require_once __DIR__ . '/../../Support/ChatTurnSqlSchema.php';
        ChatTurnSqlSchema::create($this->sql, $driver !== 'pdo_sqlite');
        $migration = new Version20260930000100($this->sql, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $statement = $query->getStatement();
            if ($driver !== 'pdo_sqlite') {
                $statement = str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $statement);
            }
            $this->sql->executeStatement($statement);
        }
        $this->stops = new ChatStopRequests($this->sql);
        $this->journal = new ChatTurnJournal($this->sql, $this->stops);
    }

    protected function tearDown(): void
    {
        $this->sql->close();
    }

    private function begin(string $id = 'turn'): void
    {
        $this->stops->register('alice', 'thread', 'web', $id);
        $this->journal->begin($id, 'alice', 'thread', 'web', $id);
    }

    private function history(): UserChatHistory
    {
        return new UserChatHistory(new InMemorySession([Auth::USERID => 'alice']),
            $this->sql->getNativeConnection(), threadId: 'thread', createIfMissing: false);
    }

    public function testStopBeforeSuccessPersistsActualStatusAndRetryNeverRepeatsCallback(): void
    {
        $this->begin();
        $this->stops->request('alice', 'thread', 'web', 'turn');
        $user = new UserMessage('accepted');
        $partial = new AssistantMessage('partial');
        $callbacks = 0;
        $result = $this->journal->succeed('turn', 'alice', function (Connection $sql, string $actual) use (
            $user, $partial, &$callbacks,
        ): void {
            ++$callbacks;
            self::assertSame($this->sql, $sql);
            self::assertSame('stopped', $actual);
            self::assertSame('stopped', $this->journal->get('turn')['status']);
            self::assertTrue($sql->isTransactionActive());
            $this->history()->persistStoppedTurn([$user, $partial], 'assistant-turn', 'turn');
        });
        self::assertSame('stopped', $result['status']);
        self::assertNull($this->sql->fetchOne('SELECT checkpoint FROM chat_turn'));
        self::assertSame('partial', $this->history()->getMessages()[1]->getContent());
        $before = $this->sql->fetchAssociative('SELECT * FROM chat_history');
        self::assertSame($result, $this->journal->complete('turn', 'alice', 'succeeded', static function (): void {
            self::fail('Repeated terminal callback');
        }));
        self::assertSame($result, $this->journal->rollback('turn', 'alice'));
        self::assertSame($before, $this->sql->fetchAssociative('SELECT * FROM chat_history'));
        self::assertSame(1, $callbacks);
    }

    public function testSuccessBeforeStopAndOldStopCannotAffectNextTurn(): void
    {
        $this->begin();
        $this->journal->complete('turn', 'alice', 'succeeded', static function (Connection $sql): void {
            self::assertTrue($sql->isTransactionActive()); // Existing one-argument callbacks remain valid.
        });
        self::assertSame('succeeded', $this->stops->request('alice', 'thread', 'web', 'turn')['status']);
        $this->begin('next');
        self::assertFalse($this->stops->isRequested('alice', 'thread', 'web', 'next'));
        self::assertSame('succeeded', $this->journal->succeed('next', 'alice')['status']);
    }

    public function testFailedStoppedCallbackRollsBackRegistryJournalAndHistoryTogether(): void
    {
        $this->begin();
        $this->stops->request('alice', 'thread', 'web', 'turn');
        $before = $this->sql->fetchAssociative('SELECT * FROM chat_history');
        try {
            $this->journal->succeed('turn', 'alice', function (): void {
                $this->history()->persistStoppedTurn([new UserMessage('accepted')], 'assistant-turn', 'turn');
                throw new RuntimeException('simulated projection failure');
            });
            self::fail('Callback failure swallowed');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated projection failure', $exception->getMessage());
        }
        self::assertSame($before, $this->sql->fetchAssociative('SELECT * FROM chat_history'));
        self::assertSame('running', $this->journal->get('turn')['status']);
        self::assertSame('accepted', $this->stops->request('alice', 'thread', 'web', 'turn')['status']);
        self::assertNotNull($this->sql->fetchOne('SELECT checkpoint FROM chat_turn'));
    }

    public function testIndependentlyTerminalRegistryNeverAuthorizesSecondHistoryMutation(): void
    {
        $this->begin();
        $this->sql->transactional(fn () => $this->stops->arbitrateTerminal('alice', 'thread', 'web', 'turn', 'stopped'));
        $before = $this->sql->fetchAssociative('SELECT * FROM chat_history');
        try {
            $this->journal->succeed('turn', 'alice', static function (): void {
                self::fail('Lost arbitration invoked callback');
            });
            self::fail('Inconsistent terminal accepted');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('terminal conflict', $exception->getMessage());
        }
        self::assertSame($before, $this->sql->fetchAssociative('SELECT * FROM chat_history'));
        self::assertSame('running', $this->journal->get('turn')['status']);
    }

    public function testCrashWithRequestedStopRestoresCheckpointInsteadOfClaimingPartialSuccess(): void
    {
        new UserChatHistory(new InMemorySession([Auth::USERID => 'alice']),
            $this->sql->getNativeConnection(), threadId: 'thread')->addMessage(new UserMessage('earlier'));
        $before = $this->sql->fetchOne('SELECT stored_messages FROM chat_history');
        $this->begin();
        $this->history()->addMessage(new AssistantMessage('uncommitted partial'));
        $this->stops->request('alice', 'thread', 'web', 'turn');
        self::assertSame('rolled_back', $this->journal->rollback('turn', 'alice')['status']);
        self::assertSame('rolled_back', $this->stops->request('alice', 'thread', 'web', 'turn')['status']);
        self::assertSame($before, $this->sql->fetchOne('SELECT stored_messages FROM chat_history'));
    }

    public function testStaleFacadeCannotOverwriteTerminalSnapshot(): void
    {
        $this->begin();
        $stale = $this->history();
        try {
            $this->journal->complete('turn', 'alice', 'stopped', fn () =>
                $stale->persistStoppedTurn([new UserMessage('accepted')], 'assistant-turn', 'turn'));
            self::fail('Stale facade accepted');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('refusing stale snapshot', $exception->getMessage());
        }
        self::assertSame('running', $this->journal->get('turn')['status']);
        self::assertSame('accepted', $this->sql->fetchOne('SELECT status FROM chat_stop_request'));
    }

    public static function endings(): array
    {
        return ['user-only' => ['user'], 'partial-answer' => ['assistant'], 'settled-tools' => ['tools']];
    }

    #[DataProvider('endings')]
    public function testStoppedTranscriptSurvivesReloadAndNextRealTurnWithoutFakeAssistant(string $ending): void
    {
        $this->begin();
        $user = (new UserMessage('accepted'))->addMetadata('claire_submission_id', 'submission-turn')
            ->addMetadata('timestamp', '2026-09-30T10:00:00+00:00');
        $messages = [$user];
        if ($ending === 'assistant') {
            $messages[] = (new AssistantMessage('partial'))->addMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA, 'audio');
        } elseif ($ending === 'tools') {
            $calls = [(new ToolCall('completed', 'call1'))->setResult('result'),
                (new ToolCall('cancelled', 'call2'))->setResult(ToolOutput::error('CANCELLED: not executed'))];
            $messages[] = new ToolCallMessage('narration', $calls);
            $messages[] = new ToolResultMessage($calls);
        }
        // Simulate the prefix already persisted by Neuron before a stop at a later boundary.
        $writer = $this->history();
        foreach ($messages as $message) {
            $writer->addMessage($message);
        }
        $this->journal->complete('turn', 'alice', 'stopped', fn () =>
            $this->history()->persistStoppedTurn($messages, 'assistant-turn', 'turn'));
        $history = $this->history();
        self::assertCount(count($messages), $history->getStoredMessages());
        self::assertSame(array_map(fn (Message $m) => $m->getId(), $messages),
            array_map(fn (Message $m) => $m->getId(), $history->getStoredMessages()));
        self::assertSame('2026-09-30T10:00:00+00:00', $history->getMessages()[0]->getMetadata('timestamp'));
        foreach ($history->getStoredMessages() as $stored) {
            self::assertNotEmpty($stored->getMetadata('timestamp'));
        }
        $last = $history->getMessages()[array_key_last($messages)];
        self::assertTrue($last->getMetadata('generation_stopped'));
        self::assertNull($last->getMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA));
        $formatted = $history->getFormattedMessages();
        self::assertTrue($formatted[array_key_last($formatted)]['stopped']);
        if ($ending === 'tools') {
            self::assertTrue($formatted[1]['toolsCall'][1]['interrupted']);
            self::assertSame('assistant-turn', $formatted[1]['id']);
        }
        if ($ending === 'user') {
            self::assertCount(1, $history->getDisplayMessages());
            self::assertSame('user', $last->getRole());
        }
        $this->begin('next');
        $history = $this->history();
        $provider = new FakeAIProvider(new AssistantMessage('next answer'));
        $agent = Agent::make()->setThreadId('thread')->setAiProvider($provider)
            ->setMessageStore($history->messageStore())->setResources(fn () => new AgentResources(
                $provider, new WorkingChatHistory($history, 'thread'), new SystemMessage('test'), new ToolRegistry()));
        self::assertSame('next answer', $agent->chat(new UserMessage('next question'))->getMessage()->getContent());
        $provider->assertCallCount(1);
        self::assertCount(count($messages) + 2, $this->history()->getStoredMessages());
    }

    public function testStopBoundaryDoesNotPermitDanglingCallsOrUnmarkedDoubleUsers(): void
    {
        foreach ([
            [new UserMessage('first'), new UserMessage('invalid')],
            [new UserMessage('first'), (new ToolCallMessage('', [new ToolCall('tool', 'call')]))
                ->addMetadata('generation_stopped', true), new UserMessage('invalid')],
        ] as $invalid) {
            try {
                new GenerationStopHistoryTrimmer()->trim($invalid, 50000);
                self::fail('Invalid alternation accepted');
            } catch (ChatHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLostStoppedCommitAcknowledgementNeverReplaysCallbackOrRestoresCheckpoint(): void
    {
        $this->begin();
        $this->sql->loseAcknowledgement = true;
        try {
            $this->journal->complete('turn', 'alice', 'stopped', fn () =>
                $this->history()->persistStoppedTurn([new UserMessage('accepted')], 'assistant-turn', 'turn'));
            self::fail('Lost acknowledgement not simulated');
        } catch (\Throwable $exception) {
            self::assertSame('Stopped commit acknowledgement lost',
                ($exception->getPrevious() ?? $exception)->getMessage());
        }
        self::assertSame('stopped', $this->journal->get('turn')['status']);
        self::assertSame('stopped', $this->journal->complete('turn', 'alice', 'succeeded', static function (): void {
            self::fail('Committed callback replayed');
        })['status']);
        self::assertSame('stopped', $this->journal->rollback('turn', 'alice')['status']);
        self::assertSame('accepted', $this->history()->getMessages()[0]->getContent());
        self::assertNull($this->sql->fetchOne('SELECT checkpoint FROM chat_turn'));
    }

    public function testTwoConnectionsCannotPublishStopWhileTerminalTransactionIsUncommitted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'claire-journal-stop-');
        self::assertNotFalse($path);
        $worker = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $http = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        try {
            ChatTurnSqlSchema::create($worker);
            $migration = new Version20260930000100($worker, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $worker->executeStatement($query->getStatement());
            }
            $http->executeStatement('PRAGMA busy_timeout = 1');
            $workerStops = new ChatStopRequests($worker);
            $httpStops = new ChatStopRequests($http);
            $journal = new ChatTurnJournal($worker, $workerStops);
            foreach ([true, false] as $commit) {
                $id = $commit ? 'commit' : 'abort';
                $workerStops->register('alice', 'thread', 'web', $id);
                $journal->begin($id, 'alice', 'thread', 'web', $id);
                try {
                    $journal->succeed($id, 'alice', static function () use ($httpStops, $id, $commit): void {
                        try {
                            $httpStops->request('alice', 'thread', 'web', $id);
                            self::fail('Stop bypassed terminal transaction lock');
                        } catch (\Doctrine\DBAL\Exception $exception) {
                            self::assertStringContainsString('locked', $exception->getMessage());
                        }
                        if (! $commit) {
                            throw new RuntimeException('abort callback');
                        }
                    });
                } catch (RuntimeException $exception) {
                    self::assertFalse($commit);
                    self::assertSame('abort callback', $exception->getMessage());
                }
                self::assertSame($commit ? 'succeeded' : 'accepted',
                    $httpStops->request('alice', 'thread', 'web', $id)['status']);
                self::assertSame($commit ? 'succeeded' : 'stopped', $journal->succeed($id, 'alice')['status']);
            }
        } finally {
            $worker->close();
            $http->close();
            unlink($path);
        }
    }
}
