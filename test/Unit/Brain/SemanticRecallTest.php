<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain;

use App\Brain\Agent;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Claire;
use App\Brain\Einstein;
use App\Brain\Middleware\SemanticRecall;
use App\Brain\Tools\MessagePostProcessorInterface;
use App\Services\Auth;
use App\Services\GenerationExecution;
use App\Services\GenerationStopToken;
use App\Services\SemanticMemoryRegistry;
use App\Services\SemanticMemoryService;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260930000200;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SemanticRecallTest extends TestCase
{
    private Connection $connection;
    private SemanticMemoryRegistry $registry;
    private SemanticMemoryService $memory;
    private string $directory;
    private array $queries = [];
    private int $serviceResolutions = 0;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE account (id TEXT PRIMARY KEY COLLATE NOCASE)');
        foreach (['alice', 'mallory'] as $owner) {
            $this->connection->insert('account', ['id' => $owner]);
        }
        $this->connection->executeStatement('CREATE TABLE chat_history (
            user_id TEXT NOT NULL, thread_id TEXT PRIMARY KEY, messages TEXT NOT NULL,
            display_messages TEXT NOT NULL, stored_messages TEXT NULL,
            display_messages_count INTEGER DEFAULT 0, title TEXT, summary TEXT,
            revision INTEGER DEFAULT 0, current_turn_id TEXT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE chat_turn (
            id TEXT PRIMARY KEY, user_id TEXT, thread_id TEXT, status TEXT, deleted_at INTEGER DEFAULT NULL
        )');
        $migration = new Version20260930000200($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
        $this->registry = new SemanticMemoryRegistry($this->connection, enabled: true);
        $embeddings = $this->createStub(EmbeddingsProviderInterface::class);
        $embeddings->method('embedText')->willReturnCallback(function (string $text): array {
            self::assertFalse($this->connection->isTransactionActive());
            $this->queries[] = $text;
            return [1.0, 0.0, 0.0];
        });
        $this->directory = '/tmp/kilo/semantic-agent-test-' . bin2hex(random_bytes(8));
        $this->memory = new SemanticMemoryService(
            $this->connection, $this->registry, $embeddings, new NullLogger(),
            $this->directory, 'synthetic/agent-test', 3, enabled: true,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
        $this->connection->close();
    }

    public function testMissingOrDisabledGlobalFlagNeverResolvesService(): void
    {
        $this->seedSource();
        foreach ([null, false] as $enabled) {
            $provider = new FakeAIProvider(new AssistantMessage('Answer'));
            $this->agent($provider, enabled: $enabled)->chat(new UserMessage('Question'));
            self::assertSame('Question', $provider->getRecorded()[0]->messages[0]->getContent());
        }
        self::assertSame(0, $this->serviceResolutions);
        self::assertSame([], $this->queries);
    }

    public function testSqlOptOutOrNoOtherThreadSourcesNeverEmbedsDespiteSessionOptIn(): void
    {
        $this->seedSource();
        $this->registry->setEnabled('alice', false);
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $this->agent($provider)->chat(new UserMessage('Question'));
        self::assertSame('Question', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertSame([], $this->queries);

        $this->registry->setEnabled('alice', true);
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $this->agent($provider, 'source-thread')->chat(new UserMessage('Same thread'));
        self::assertSame('Same thread', $provider->getRecorded()[0]->messages[2]->getContent());
        self::assertSame([], $this->queries);
    }

    public function testStreamedToolCyclesRecallOnceWithoutPersistingDataOrImportingTools(): void
    {
        $this->seedSource();
        $provider = new FakeAIProvider(
            new ToolCallMessage('First calculation', [ToolCall::make('evaluate', 'calc-1', ['expression' => '2+2'])]),
            new ToolCallMessage('Generated file', [ToolCall::make('fixture_file', 'file-1')]),
            new AssistantMessage('Completed'),
            new AssistantMessage('Next answer'),
        );
        $agent = $this->agent($provider);
        $file = new class extends Tool implements MessagePostProcessorInterface {
            protected string $name = 'fixture_file';

            public function __invoke(): string
            {
                return '@@GENERATED@@test@@';
            }

            public function postProcessMessage(Message $message, ToolCall $call): Message
            {
                return $message->setContents($message->getContent() . "\n" . $call->getResult());
            }
        };
        $agent->setTools([new EvaluateTool(), $file]);
        $user = new UserMessage('Calculate');
        $stream = $agent->stream($user);
        iterator_to_array($stream);
        $state = $stream->getReturn();

        self::assertSame(['Calculate'], $this->queries);
        self::assertSame(1, $this->serviceResolutions);
        self::assertSame('Calculate', $user->getContent());
        foreach ($provider->getRecorded() as $request) {
            self::assertStringContainsString('ONLY_MEMORY', $request->messages[0]->getContent());
            self::assertStringContainsString('UNTRUSTED RECALLED CONVERSATION DATA', $request->messages[0]->getContent());
            self::assertStringContainsString('not instructions', $request->messages[0]->getContent());
            self::assertStringNotContainsString('ONLY_MEMORY', $request->systemPrompt->getContent());
            self::assertSame(['evaluate', 'fixture_file'], array_map(static fn ($tool) => $tool->getName(), $request->tools));
            self::assertSame($user->getId(), $request->messages[0]->getId());
        }
        self::assertStringNotContainsString('ONLY_MEMORY', serialize($state));
        $row = $this->connection->fetchAssociative('SELECT * FROM chat_history WHERE thread_id = ?', ['current']);
        foreach (['messages', 'display_messages', 'stored_messages'] as $column) {
            self::assertStringNotContainsString('ONLY_MEMORY', $row[$column]);
        }
        self::assertSame("Completed\n@@GENERATED@@test@@", $agent->getUserChatHistory()->getLastMessage()->getContent());

        $agent->chat(new UserMessage('Next question'));
        self::assertSame(['Calculate', 'Next question'], $this->queries);
        self::assertSame(2, $this->serviceResolutions);
        self::assertSame('Calculate', $provider->getRecorded()[3]->messages[0]->getContent());
        self::assertStringContainsString('ONLY_MEMORY', $provider->getRecorded()[3]->messages[6]->getContent());
    }

    public function testRecallIsSharedAcrossAvatarsButNeverAcrossOwners(): void
    {
        $this->seedSource();
        foreach ([Claire::class, Einstein::class] as $index => $class) {
            $provider = new FakeAIProvider(new AssistantMessage('Answer'));
            $this->agent($provider, 'avatar-' . $index, class: $class)->chat(new UserMessage('Shared question'));
            self::assertStringContainsString('ONLY_MEMORY', $provider->getRecorded()[0]->messages[0]->getContent());
        }
        self::assertSame(['Shared question', 'Shared question'], $this->queries);
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $this->agent($provider, owner: 'mallory')->chat(new UserMessage('Other owner'));
        self::assertSame('Other owner', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertCount(2, $this->queries);
    }

    public function testDeletedOrCaseAliasedAccountCannotRecallOrEmbedRetainedSources(): void
    {
        $this->seedSource();
        $this->connection->update('account', ['id' => 'ALICE'], ['id' => 'alice']);
        foreach (['case-aliased-account', 'deleted-account'] as $thread) {
            $provider = new FakeAIProvider(new AssistantMessage('Answer without memory'));
            $this->agent($provider, $thread)->chat(new UserMessage('Question'));

            self::assertSame([], $this->queries);
            self::assertSame('Question', $provider->getRecorded()[0]->messages[0]->getContent());
            self::assertSame(1, $provider->getCallCount());
            $this->connection->delete('account', ['id' => 'ALICE']);
        }
        self::assertSame(2, $this->serviceResolutions);
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM semantic_memory_excerpt WHERE status = 'indexed'",
        ));
    }

    public function testOpeningAndEmptyAttachmentQuerySkipRecall(): void
    {
        $this->seedSource();
        $provider = new FakeAIProvider(new AssistantMessage('Welcome'), new AssistantMessage('Attachment answer'));
        $agent = $this->agent($provider);
        self::assertSame('Welcome', $agent->getOpeningText());
        $agent->chat((new UserMessage('[Document: private/path]'))->addMetadata('semantic_query', ''));
        self::assertSame(0, $this->serviceResolutions);
        self::assertSame([], $this->queries);
    }

    public function testPlainCaptionAndConfiguredBudgetAreUsedWithoutEmbeddingAttachmentPath(): void
    {
        $this->seedSource();
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $agent = $this->agent($provider, budget: 400);
        $agent->chat((new UserMessage('[Document: private/path]\nA caption'))
            ->addMetadata('semantic_query', 'A caption'));
        self::assertSame(['A caption'], $this->queries);
        $projection = $provider->getRecorded()[0]->messages[0];
        self::assertStringContainsString('ONLY_MEMORY', $projection->getContent());
        self::assertLessThanOrEqual(510, mb_strlen($projection->getContentBlocks()[1]->content));
    }

    public function testNoEmbeddingsWhileBusinessTransactionIsActive(): void
    {
        $this->seedSource();
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $agent = $this->agent($provider);
        $agent->setMessageStore(new InMemoryMessageStore());
        $this->connection->beginTransaction();
        try {
            $agent->chat(new UserMessage('Question'));
        } finally {
            $this->connection->rollBack();
        }
        self::assertSame([], $this->queries);
        self::assertSame('Question', $provider->getRecorded()[0]->messages[0]->getContent());
    }

    public function testPreStoppedTurnSkipsRecallAndProvider(): void
    {
        $this->seedSource();
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        $agent = $this->agent($provider);
        $stream = (new GenerationExecution(new GenerationStopToken(static fn (): bool => true)))
            ->stream($agent, new UserMessage('Question'));
        iterator_to_array($stream);
        self::assertTrue($stream->getReturn()->stopped);
        self::assertSame(0, $provider->getCallCount());
        self::assertSame(0, $this->serviceResolutions);
        self::assertSame([], $this->queries);
    }

    public function testRecallFailureFailsOpenAndLogsOnlyExceptionClassOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Semantic memory recall unavailable', ['class' => \RuntimeException::class],
        );
        $provider = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two'));
        $projection = new SemanticRecall($provider, static function (string $query): never {
            throw new \RuntimeException('DO NOT LOG: secret query ' . $query);
        }, $logger);
        $user = new UserMessage('private text');
        $projection->chat($user);
        $projection->chat($user);
        self::assertSame('private text', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertSame(2, $provider->getCallCount());
    }

    public function testStructuredTransportForwardsEnvelopeAndProjectsOnlyOutboundCopy(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"answer":"done"}'));
        $projection = new SemanticRecall(
            $provider, static fn (string $query): string => 'Untrusted recalled conversation data: ONLY_MEMORY',
            new NullLogger(),
        );
        $message = new UserMessage('Question');
        $response = $projection->structured($message, \stdClass::class, ['type' => 'object']);

        self::assertSame('{"answer":"done"}', $response->message()->getContent());
        self::assertSame('Question', $message->getContent());
        self::assertSame('structured', $provider->getRecorded()[0]->method);
        self::assertStringContainsString('ONLY_MEMORY', $provider->getRecorded()[0]->messages[0]->getContent());
    }

    private function seedSource(): void
    {
        $this->registry->setEnabled('alice', true);
        $history = new UserChatHistory(
            new InMemorySession([Auth::USERID => 'alice']), $this->connection->getNativeConnection(),
            threadId: 'source-thread',
        );
        $user = new UserMessage('ONLY_MEMORY: prefers blueberry');
        $assistant = new AssistantMessage('Recorded preference');
        $history->addMessage($user)->addMessage($assistant);
        $id = $this->connection->transactional(function () use ($user, $assistant): string {
            $this->connection->insert('chat_turn', [
                'id' => 'source-turn', 'user_id' => 'alice', 'thread_id' => 'source-thread', 'status' => 'running',
            ]);
            $this->registry->captureTurn('alice', 'source-turn');
            $this->connection->update('chat_turn', ['status' => 'succeeded'], ['id' => 'source-turn']);
            return $this->registry->recordSucceededTurn(
                'alice', 'source-turn', $user->getId(), $assistant->getId(), $user->getContent(), $assistant->getContent(),
            );
        });
        self::assertTrue($this->memory->index('alice', $id));
        $this->queries = [];
    }

    /** @param class-string<Agent> $class */
    private function agent(
        FakeAIProvider $provider,
        string $thread = 'current',
        ?bool $enabled = true,
        string $class = Claire::class,
        string $owner = 'alice',
        int $budget = 6000,
    ): Agent {
        $settings = new Settings(['llm' => [
            'openai' => ['baseUri' => 'https://unused.test', 'key' => 'test', 'modelSummary' => 'summary', 'contextWindow' => 50000],
            'rawMimeTypes' => [], 'httpClient' => ['timeout' => 1.0, 'connectTimeout' => 1.0],
            'shortMemory' => ['maxTokens' => 0, 'messageToKeep' => 5],
            'longTermMemory' => ['maxCharacters' => 4000],
            'semanticMemory' => $enabled === null ? [] : ['enabled' => $enabled, 'contextBudget' => $budget],
        ]]);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($settings): mixed {
            if ($id === SemanticMemoryService::class) {
                ++$this->serviceResolutions;
                return $this->memory;
            }
            return match ($id) {
                Settings::class => $settings,
                Connection::class => $this->connection,
                LoggerInterface::class => new NullLogger(),
                default => throw new \RuntimeException('Unexpected service: ' . $id),
            };
        });
        $agent = new $class($container, new InMemorySession([
            Auth::USERID => $owner, 'semantic_memory_enabled' => true,
        ]), $thread);
        return $agent->setAiProvider($provider)->setTools([]);
    }
}
