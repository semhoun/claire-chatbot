<?php

declare(strict_types=1);

namespace Test\Unit\Brain\ChatHistory;

use App\Brain\ChatHistory\SummaryChatHistory;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\ChatHistory\WorkingChatHistory;
use App\Brain\Middleware\ShortMemory;
use App\Brain\Middleware\ToolCalls;
use App\Brain\Tools\MessagePostProcessorInterface;
use App\Services\Auth;
use App\Services\Session\InMemorySession;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HistoryFixtureTool extends Tool implements MessagePostProcessorInterface
{
    protected string $name = 'fixture_file';
    public static int $executions = 0;

    public function __invoke(): string
    {
        return '@@GENERATED@@/file-' . ++self::$executions;
    }

    public function postProcessMessage(Message $message, ToolCall $call): Message
    {
        return (clone $message)->setContents($message->getContent() . ' ' . $call->getResult());
    }
}

final class UserMessageStoreTest extends TestCase
{
    private PDO $pdo;
    private InMemorySession $session;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY,
            messages TEXT NOT NULL, display_messages TEXT NOT NULL, stored_messages TEXT NULL,
            display_messages_count INTEGER DEFAULT 0, title TEXT, summary TEXT,
            revision INTEGER DEFAULT 0, current_turn_id TEXT NULL)");
        $this->session = new InMemorySession([]);
        $this->session->set(Auth::USERID, 'alice');
    }

    private function history(string $thread = 'thread'): UserChatHistory
    {
        return new UserChatHistory($this->session, $this->pdo, threadId: $thread);
    }

    public function testConsultationNeverCreatesOrRewritesLegacyRows(): void
    {
        $history = $this->history();
        self::assertSame([], $history->messageStore()->loadAll('thread'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM chat_history')->fetchColumn());
        $history->addMessage(new AssistantMessage('Opening'));
        $before = $this->pdo->query('SELECT * FROM chat_history')->fetch(PDO::FETCH_ASSOC);
        $fresh = $this->history();
        self::assertInstanceOf(UserMessage::class, $fresh->getMessages()[0]);
        self::assertSame($before, $this->pdo->query('SELECT * FROM chat_history')->fetch(PDO::FETCH_ASSOC));
    }

    public function testArchivesKeepVisibleAndInternalTranscriptAndAppendIsIdempotent(): void
    {
        $history = $this->history();
        $store = $history->messageStore();
        $internal = (new UserMessage('private context'))->addMetadata('message_type', 'out_of_context');
        $answer = new AssistantMessage('answer');
        $store->append('thread', $internal);
        $store->append('thread', $answer);
        $store->archive('thread', 2);
        $before = $this->pdo->query('SELECT * FROM chat_history')->fetch(PDO::FETCH_ASSOC);
        $store->append('thread', (clone $answer)->setContents('must not update'));
        self::assertSame($before, $this->pdo->query('SELECT * FROM chat_history')->fetch(PDO::FETCH_ASSOC));
        self::assertSame([], $store->loadActive('thread'));
        self::assertSame(['private context', 'answer'], array_map(fn (Message $m) => $m->getContent(),
            $store->loadAll('thread')));
        self::assertSame('answer', $history->getDisplayMessages()[0]->getContent());
        self::assertSame([$internal->getId()], array_map(fn (Message $m) => $m->getId(),
            $store->loadAll('thread', 1, $answer->getId())));
        self::assertSame([], $store->loadAll('thread', 5, 'unknown'));
        self::assertSame($answer->getId(), $store->loadAll('thread', 1)[0]->getId());
        $store->clear('thread');
        self::assertSame([], $store->loadAll('thread'));
        self::assertSame([], $history->getDisplayMessages());
    }

    public function testFrozenV3FixturePreservesRawToolMetadataAndIdentities(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/neuron-v3/history.json'),
            true, flags: JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare('INSERT INTO chat_history'
            . ' (user_id, thread_id, messages, display_messages) VALUES (?, ?, ?, ?)');
        $statement->execute(['alice', 'thread', json_encode($fixture['messages'], JSON_THROW_ON_ERROR),
            json_encode($fixture['display_messages'], JSON_THROW_ON_ERROR)]);
        $history = $this->history();
        self::assertNull($this->pdo->query('SELECT stored_messages FROM chat_history')->fetchColumn());
        $result = $history->getDisplayMessages()[4];
        self::assertSame('msg_result_1', $result->getId());
        self::assertSame('2026-01-02T03:04:09+00:00', $result->getMetadata('timestamp'));
        self::assertSame(['text' => 'Synthetic fixture.'], $result->getToolCalls()[0]->getInputs());
        self::assertSame('synthetic', $history->getDisplayMessages()[2]->getContentBlocks()[2]->getMetadata('purpose'));
        self::assertCount(9, $history->messageStore()->loadAll('thread'));
        $history->addMessage(new UserMessage('new v4 turn'));
        $fresh = $this->history();
        self::assertCount(10, $fresh->messageStore()->loadAll('thread'));
        self::assertSame('audio_fixture_final', $fresh->getDisplayMessages()[7]
            ->getMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA));
        self::assertSame('msg_summary', $fresh->getMessages()[0]->getId());
        self::assertSame('msg_result_1', $fresh->getDisplayMessages()[4]->getId());
    }

    public function testLegacyDuplicateTextGetsDistinctDeterministicIds(): void
    {
        $payload = [['role' => 'user', 'content' => 'same'], ['role' => 'assistant', 'content' => 'same'],
            ['role' => 'user', 'content' => 'same'], ['role' => 'assistant', 'content' => 'same']];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare('INSERT INTO chat_history'
            . ' (user_id, thread_id, messages, display_messages) VALUES (?, ?, ?, ?)');
        $statement->execute(['alice', 'thread', $json, $json]);
        $first = $this->history();
        $ids = array_map(fn (Message $m) => $m->getId(), $first->getMessages());
        self::assertCount(4, array_unique($ids));
        self::assertSame($ids, array_map(fn (Message $m) => $m->getId(), $this->history()->getDisplayMessages()));
        $first->archiveMessages(2);
        self::assertCount(4, $this->history()->messageStore()->loadAll('thread'));
    }

    public function testExplicitUpdateChangesCanonicalAndBothProjectionsWithoutLosingAudio(): void
    {
        $history = $this->history();
        $history->addMessage(new UserMessage('question'));
        $answer = new AssistantMessage('answer');
        $history->addMessage($answer);
        $history->identifyLastAssistantMessage('claire-answer', 'audio-answer');
        $history->updateMessage((clone $answer)->setContents('answer @@GENERATED@@/file'));
        $fresh = $this->history();
        self::assertSame('answer @@GENERATED@@/file', $fresh->getMessages()[1]->getContent());
        self::assertSame('audio-answer', $fresh->getDisplayMessages()[1]
            ->getMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA));
        self::assertNull($fresh->getMessages()[1]->getMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA));
        self::assertSame('audio-answer', $fresh->messageStore()->loadAll('thread')[1]
            ->getMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA));
    }

    public function testStoreRejectsSwitchingFacadeThreadAndOwner(): void
    {
        $history = $this->history();
        $store = $history->messageStore();
        $history->setThreadId('other');
        try {
            $store->loadAll('thread');
            self::fail('Store followed mutable facade thread');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('thread mismatch', $exception->getMessage());
        }
        $other = $history->messageStore();
        $this->session->set(Auth::USERID, 'bob');
        $this->expectExceptionMessage('owner mismatch');
        $other->append('other', new UserMessage('unauthorized'));
    }

    public function testCanonicalSnapshotGuardRejectsAnUnversionedExternalWrite(): void
    {
        $history = $this->history();
        $history->addMessage(new UserMessage('question'));
        $store = $history->messageStore();
        $this->pdo->exec("UPDATE chat_history SET stored_messages = '[]'");
        $this->expectExceptionMessage('refusing stale snapshot');
        $store->append('thread', new AssistantMessage('stale'));
    }

    public function testRealStreamingAgentPostProcessesAllToolRoundsBeforeCompletion(): void
    {
        HistoryFixtureTool::$executions = 0;
        $history = $this->history();
        $provider = new FakeAIProvider(
            new ToolCallMessage('First narration.', [new ToolCall('fixture_file', 'call-1')]),
            new ToolCallMessage('Second narration.', [new ToolCall('fixture_file', 'call-2')]),
            new AssistantMessage('Final'),
        );
        $agent = Agent::make()->setThreadId('thread')->setMessageStore($history->messageStore())
            ->setAiProvider($provider)->setTools([new HistoryFixtureTool()]);
        $middleware = new ToolCalls($history);
        $agent->addMiddleware(InferenceNode::class, $middleware)->addMiddleware(AgentEndNode::class, $middleware);
        $stream = $agent->stream(new UserMessage('Generate two files'));
        iterator_to_array($stream);
        self::assertFalse($stream->getReturn()->isInterrupted());
        self::assertSame('Final @@GENERATED@@/file-1 @@GENERATED@@/file-2',
            $stream->getReturn()->getMessage()->getContent());
        self::assertSame(2, HistoryFixtureTool::$executions);
        $provider->assertCallCount(3);
        $fresh = $this->history();
        self::assertSame($stream->getReturn()->getMessage()->getContent(), $fresh->getMessages()[5]->getContent());
        self::assertSame('First narration.Second narration.Final @@GENERATED@@/file-1 @@GENERATED@@/file-2',
            $fresh->getFormattedMessages()[1]['message']);
        self::assertCount(6, $fresh->messageStore()->loadAll('thread'));
    }

    public function testRealAgentSummaryArchivesInsteadOfErasingTranscript(): void
    {
        $history = $this->history();
        foreach ([new UserMessage(str_repeat('old question ', 40)), new AssistantMessage('old answer'),
            new UserMessage('recent question'), new AssistantMessage('recent answer')] as $message) {
            $history->addMessage($message);
        }
        $provider = new FakeAIProvider(new AssistantMessage('next answer'));
        $summary = new FakeAIProvider(new AssistantMessage('compressed context'));
        $agent = Agent::make()->setThreadId('thread')->setAiProvider($provider)
            ->setMessageStore($history->messageStore())
            ->setResources(fn () => new AgentResources($provider, new WorkingChatHistory($history, 'thread'),
                new SystemMessage('test'), new ToolRegistry()));
        $agent->addMiddleware(InferenceNode::class, new ShortMemory(new NullLogger(), $summary, 1, 1));
        $state = $agent->chat(new UserMessage('next question'));
        self::assertSame('next answer', $state->getMessage()->getContent());
        self::assertCount(4, $history->getMessages());
        self::assertSame('out_of_context', $history->getMessages()[0]->getMetadata('message_type'));
        self::assertCount(6, $history->getDisplayMessages());
        self::assertCount(7, $history->messageStore()->loadAll('thread'));
        $summary->assertCallCount(1);
        $provider->assertCallCount(1);
    }

    public function testSummaryStoreCannotWriteUserHistory(): void
    {
        $history = new ChatHistory(new SummaryChatHistory(), 'summary');
        $history->addMessage(new UserMessage('summary prompt'));
        $history->addMessage(new AssistantMessage('summary'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM chat_history')->fetchColumn());
    }

    public function testDeletingArchivedLastExchangeCannotResurrectCanonicalMessages(): void
    {
        $history = $this->history();
        foreach ([new UserMessage('first'), new AssistantMessage('first answer'),
            new UserMessage('last'), new AssistantMessage('last answer')] as $message) {
            $history->addMessage($message);
        }
        $history->archiveMessages(4);
        self::assertSame('last', $history->removeLastExchange());
        $fresh = $this->history();
        self::assertCount(2, $fresh->messageStore()->loadAll('thread'));
        self::assertCount(2, $fresh->getDisplayMessages());
        self::assertSame([], $fresh->getMessages());
    }

    public function testLegacyActiveTailMatchesLatestDuplicateOccurrence(): void
    {
        $turn = [['role' => 'user', 'content' => 'same'], ['role' => 'assistant', 'content' => 'same']];
        $statement = $this->pdo->prepare('INSERT INTO chat_history'
            . ' (user_id, thread_id, messages, display_messages) VALUES (?, ?, ?, ?)');
        $statement->execute(['alice', 'thread', json_encode($turn, JSON_THROW_ON_ERROR),
            json_encode([...$turn, ...$turn], JSON_THROW_ON_ERROR)]);
        $history = $this->history();
        self::assertSame($history->getDisplayMessages()[2]->getId(), $history->getMessages()[0]->getId());
        self::assertSame($history->getDisplayMessages()[3]->getId(), $history->getMessages()[1]->getId());
        self::assertCount(4, $history->messageStore()->loadAll('thread'));
    }
}
