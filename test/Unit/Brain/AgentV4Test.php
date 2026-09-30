<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain;

use App\Brain\Agent;
use App\Brain\Claire;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\ChatHistory\WorkingChatHistory;
use App\Brain\Einstein;
use App\Brain\LongTermMemory;
use App\Brain\Middleware\ShortMemory;
use App\Brain\Summary;
use App\Brain\Tools\PdfGeneratorTool;
use App\Brain\YamlBrain;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\PdfGeneratorService;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AgentV4Test extends TestCase
{
    public function testRealAgentRunsConsecutiveTurnsWithFreshHistoryAndTimestamps(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First answer'), new AssistantMessage('Second answer'));
        $store = new InMemoryMessageStore();
        $agent = new Claire($this->container(), new InMemorySession([]), 'thread-1');
        $agent->setAiProvider($provider)->setMessageStore($store)->setTools([]);

        $first = $agent->chat(new UserMessage('First question'));
        $second = $agent->chat(new UserMessage('Second question'));

        self::assertInstanceOf(AgentState::class, $first);
        self::assertSame('First answer', $first->getMessage()->getContent());
        self::assertSame('Second answer', $second->getMessage()->getContent());
        self::assertSame(2, $provider->getCallCount());
        self::assertCount(3, $provider->getRecorded()[1]->messages);
        self::assertCount(4, $store->loadAll('thread-1'));
        self::assertNotSame($agent->getChatHistory(), $agent->getChatHistory());
        foreach ($store->loadAll('thread-1') as $message) {
            self::assertNotNull($message->getMetadata('timestamp'));
        }
    }

    public function testStreamingToolCyclesExecuteOnceAndPostprocessAllGeneratedFiles(): void
    {
        $counter = new \ArrayObject(['calls' => 0]);
        $settings = $this->settings();
        $pdf = new class(
            new PdfGeneratorService(
                $settings,
                $this->createStub(Filesystem::class),
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(\App\Services\Markdown::class),
            ),
            $settings,
            new InMemorySession([]),
            'tool-thread',
        ) extends PdfGeneratorTool {
            public \ArrayObject $counter;

            public function __invoke(
                string $content,
                ?string $format = 'html',
                ?string $filename = null,
                ?string $page_size = 'A4',
                ?string $orientation = 'portrait',
                ?int $margin_top = 15,
                ?int $margin_bottom = 15,
                ?int $margin_left = 15,
                ?int $margin_right = 15,
            ): string {
                ++$this->counter['calls'];
                return json_encode(['status' => 'success', 'id' => '@@GENERATED@@' . $content . '@@'], JSON_THROW_ON_ERROR);
            }
        };
        $pdf->counter = $counter;
        $provider = new FakeAIProvider(
            new ToolCallMessage('Preparing one. ', [ToolCall::make('generate_pdf', 'call-1', ['content' => 'one'])]),
            new ToolCallMessage('Preparing two. ', [ToolCall::make('generate_pdf', 'call-2', ['content' => 'two'])]),
            new AssistantMessage('Your reports.'),
            new AssistantMessage('Follow-up answer.'),
        );
        $store = new InMemoryMessageStore();
        $agent = new Agent($this->container(), new InMemorySession([]), 'tool-thread');
        $agent->setAiProvider($provider)->setMessageStore($store)->setTools([$pdf]);

        $stream = $agent->stream(new UserMessage('Make two reports'));
        $narration = '';
        foreach ($stream as $chunk) {
            if ($chunk instanceof TextChunk) {
                $narration .= $chunk->content;
            }
        }
        $state = $stream->getReturn();

        self::assertSame(2, $counter['calls']);
        self::assertSame(3, $provider->getCallCount());
        self::assertSame('Preparing one. Preparing two. Your reports.', $narration);
        self::assertSame("Your reports.\n@@GENERATED@@one@@\n@@GENERATED@@two@@", $state->getMessage()->getContent());
        self::assertCount(6, $store->loadAll('tool-thread'));
        self::assertInstanceOf(ToolResultMessage::class, $store->loadAll('tool-thread')[2]);
        self::assertSame('call-1', $store->loadAll('tool-thread')[2]->getToolCalls()[0]->getCallId());
        self::assertSame('tool_call', $store->loadAll('tool-thread')[1]->getMetadata('message_type'));
        $agent->chat(new UserMessage('Continue'));
        self::assertSame(2, $counter['calls']);
        self::assertSame(4, $provider->getCallCount());
    }

    public function testYamlProfileDateAndUserInstructionsSurviveV4Composition(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('Prefers concise answers.');
        $session = new InMemorySession([
            Auth::USERID => 'user-1',
            LongTermMemory::SESSION_KEY => true,
            'user_info' => ['displayName' => 'Ada'],
        ]);
        $provider = new FakeAIProvider(new AssistantMessage('Hello Ada'));
        $agent = new YamlBrain(
            ['instruction' => 'Custom YAML agent for {{USER}}.', 'welcomes' => ['Fixed welcome']],
            $this->container($connection),
            $session,
            'yaml-thread',
        );
        $agent->setAiProvider($provider)->setMessageStore(new InMemoryMessageStore())->setTools([]);

        $agent->chat(new UserMessage('Hello'));

        $instructions = $provider->getRecorded()[0]->systemPrompt->getContent();
        self::assertStringContainsString('Custom YAML agent for Ada.', $instructions);
        self::assertStringContainsString('Prefers concise answers.', $instructions);
        self::assertStringContainsString('Date et heure actuelles', $instructions);
        self::assertStringContainsString('@@GENERATED@@', $instructions);
        self::assertStringNotContainsString('{{USER}}', $instructions);
        self::assertSame('Fixed welcome', $agent->getOpeningText());
    }

    public function testDefaultCalculatorOffersEvaluateAndPreservesAgentIdentity(): void
    {
        $agent = new Einstein($this->container(), new InMemorySession([]), 'math-thread');
        $names = [];
        foreach ($agent->getTools() as $tool) {
            foreach ($tool instanceof ToolkitInterface ? $tool->tools() : [$tool] as $capability) {
                $names[] = $capability->getName();
            }
        }

        self::assertContains('evaluate', $names);
        self::assertNotContains('sum', $names);
        self::assertNotContains('multiply', $names);
        self::assertStringContainsString('Einstein', $agent->getInstructions()->getContent());
        self::assertSame('math-thread', $agent->getThreadId());
    }

    public function testEvaluateExecutesWholeExpressionInRealAgentLoop(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('evaluate', 'math-1', ['expression' => '2*(3+4)'])]),
            new AssistantMessage('14'),
        );
        $agent = new Agent($this->container(), new InMemorySession([]), 'evaluate-thread');
        $agent->setAiProvider($provider)->setMessageStore(new InMemoryMessageStore())->setTools([new EvaluateTool()]);

        $state = $agent->chat(new UserMessage('Calculate 2*(3+4)'));

        self::assertSame('14', $state->getMessage()->getContent());
        self::assertSame('14', $provider->getRecorded()[1]->messages[2]->getToolCalls()[0]->getResult());
        self::assertSame(2, $provider->getCallCount());
    }

    public function testConfiguredSystemBlocksKeepCacheFlagsAndAreNotMutated(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello'));
        $instructions = new SystemMessage([(new SystemContent('Hello {{USER}}'))->cache()]);
        $agent = new Agent($this->container(), new InMemorySession(['user_info' => ['displayName' => 'Ada']]), 'cache-thread');
        $agent->setAiProvider($provider)->setMessageStore(new InMemoryMessageStore())->setTools([])
            ->setInstructions($instructions);

        $agent->chat(new UserMessage('Hi'));

        $blocks = $provider->getRecorded()[0]->systemPrompt->getContentBlocks();
        self::assertSame('Hello Ada', $blocks[1]->content);
        self::assertTrue($blocks[1]->isCached());
        self::assertSame('Hello {{USER}}', $instructions->getContent());
        self::assertCount(1, $instructions->getContentBlocks());
    }

    public function testApplicationResourcesCompactContextWithoutLosingVisibleTranscript(): void
    {
        $connection = $this->historyConnection();
        $session = new InMemorySession([Auth::USERID => 'alice']);
        $agent = new Agent($this->container($connection), $session, 'compact-thread');
        $provider = new FakeAIProvider(new AssistantMessage('Continued answer'));
        $summaryProvider = new FakeAIProvider(new AssistantMessage('Earlier context'));
        $agent->setAiProvider($provider)->setTools([]);
        $history = $agent->getUserChatHistory();
        for ($turn = 0; $turn < 3; ++$turn) {
            $history->addMessage(new UserMessage('Question ' . $turn));
            $history->addMessage(new AssistantMessage('Answer ' . $turn));
        }
        $agent->addMiddleware(InferenceNode::class, new ShortMemory(new NullLogger(), $summaryProvider, 1, 1));

        $resources = (new \ReflectionMethod($agent, 'resources'))->invoke($agent);
        self::assertInstanceOf(WorkingChatHistory::class, $resources->history);
        $agent->chat(new UserMessage('Continue'));

        self::assertSame(1, $summaryProvider->getCallCount());
        self::assertSame(1, $provider->getCallCount());
        self::assertStringContainsString('Earlier context', $provider->getRecorded()[0]->messages[0]->getContent());
        $fresh = new UserChatHistory($session, $connection->getNativeConnection(), threadId: 'compact-thread');
        self::assertCount(8, $fresh->getDisplayMessages());
        self::assertCount(4, $fresh->getMessages());
        self::assertCount(9, $fresh->messageStore()->loadAll('compact-thread'));
        self::assertSame('Continued answer', $fresh->getLastMessage()->getContent());
    }

    public function testSummaryUsesConversationContextWithoutPersistingItsPromptOrResponse(): void
    {
        $connection = $this->historyConnection();
        $session = new InMemorySession([Auth::USERID => 'alice']);
        $history = new UserChatHistory($session, $connection->getNativeConnection(), threadId: 'summary-thread');
        $history->addMessage(new UserMessage('Plan a report'));
        $history->addMessage(new AssistantMessage('Here is the plan'));
        $before = $connection->fetchAssociative('SELECT * FROM chat_history');
        $provider = new FakeAIProvider(new AssistantMessage('{"title":"Report","summary":"A plan","memory":""}'));
        $summary = new Summary($connection, $this->settings(), $session, 'summary-thread');
        $summary->setAiProvider($provider);

        $summary->generateAndPersist();

        $after = $connection->fetchAssociative('SELECT * FROM chat_history');
        foreach (['messages', 'display_messages', 'stored_messages', 'display_messages_count'] as $column) {
            self::assertSame($before[$column], $after[$column]);
        }
        self::assertSame('Report', $after['title']);
        self::assertSame('A plan', $after['summary']);
        self::assertSame((int) $before['revision'] + 1, (int) $after['revision']);
        self::assertCount(3, $provider->getRecorded()[0]->messages);
        self::assertSame('Plan a report', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertSame('Here is the plan', $provider->getRecorded()[0]->messages[1]->getContent());
    }

    public function testCopyBoundToAnotherThreadDoesNotReuseOwnerBoundFacade(): void
    {
        $connection = $this->historyConnection();
        $session = new InMemorySession([Auth::USERID => 'alice']);
        $provider = new FakeAIProvider(new AssistantMessage('First thread'), new AssistantMessage('Second thread'));
        $first = new Agent($this->container($connection), $session, 'first-thread');
        $first->setAiProvider($provider)->setTools([]);
        $first->chat(new UserMessage('First question'));

        $second = $first->for('second-thread');
        $second->chat(new UserMessage('Second question'));

        self::assertNotSame($first->getUserChatHistory(), $second->getUserChatHistory());
        self::assertSame('first-thread', $first->getThreadId());
        self::assertSame('second-thread', $second->getThreadId());
        self::assertSame('First thread', $first->getUserChatHistory()->getLastMessage()->getContent());
        self::assertSame('Second thread', $second->getUserChatHistory()->getLastMessage()->getContent());
        self::assertCount(1, $provider->getRecorded()[1]->messages);
    }

    private function historyConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE chat_history (
            user_id TEXT NOT NULL, thread_id TEXT PRIMARY KEY,
            messages TEXT NOT NULL, display_messages TEXT NOT NULL, stored_messages TEXT NULL,
            display_messages_count INTEGER DEFAULT 0, title TEXT, summary TEXT,
            revision INTEGER DEFAULT 0, current_turn_id TEXT NULL
        )');
        return $connection;
    }

    private function container(?Connection $connection = null): ContainerInterface
    {
        $audio = $this->createStub(AudioServiceInterface::class);
        $audio->method('isAvailable')->willReturn(false);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            [Settings::class, $this->settings()],
            [Connection::class, $connection ?? $this->createStub(Connection::class)],
            [LoggerInterface::class, $this->createStub(LoggerInterface::class)],
            [AudioServiceInterface::class, $audio],
        ]);
        return $container;
    }

    private function settings(): Settings
    {
        return new Settings([
            'llm' => [
                'openai' => ['baseUri' => 'https://unused.test', 'key' => 'test', 'modelSummary' => 'summary', 'contextWindow' => 50000],
                'rawMimeTypes' => ['text/plain'],
                'httpClient' => ['timeout' => 1.0, 'connectTimeout' => 1.0],
                'shortMemory' => ['maxTokens' => 0, 'messageToKeep' => 5],
                'longTermMemory' => ['maxCharacters' => 4000],
            ],
            'tools' => ['pdf' => ['enabled' => false], 'comfyui' => ['enabled' => false], 'searXNG' => ['enabled' => false]],
        ]);
    }
}
