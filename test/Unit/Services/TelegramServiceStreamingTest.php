<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\Agent;
use App\Brain\BrainAvatar;
use App\Brain\BrainRegistry;
use App\Brain\Middleware\ToolCalls;
use App\Entity\TelegramSession as SessionEntity;
use App\Repository\TelegramSessionRepository;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\ChatGenerationState;
use App\Services\ChatStopRequests;
use App\Services\RedisClient;
use App\Services\SemanticMemoryRegistry;
use App\Services\Session\SessionInterface;
use App\Services\Session\TelegramSession;
use App\Services\Settings;
use App\Services\TelegramAudioService;
use App\Services\TelegramChatActionHeartbeat;
use App\Services\TelegramGeneration;
use App\Services\TelegramJournal;
use App\Services\TelegramMarkdown;
use App\Services\TelegramService;
use App\Services\ThemeRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

final class StoppedTelegramTestAgent extends Agent implements BrainAvatar
{
    public function __construct(ContainerInterface $container, SessionInterface $session, ?string $threadId = null)
    {
        parent::__construct($container, $session, $threadId);
        $this->setAiProvider($container->get(FakeAIProvider::class));
        $this->setTools($container->get('test.tools'));
        $this->setContextWindow(50000);
    }

    protected function middleware(): array
    {
        return [AgentEndNode::class => [new ToolCalls($this->getUserChatHistory())]];
    }

    protected function resolveTools(): array
    {
        return \NeuronAI\Agent\Agent::resolveTools();
    }
}

final class TelegramServiceTestConnection extends Connection
{
    public ?\Closure $beforeTransaction = null;

    public function transactional(\Closure $func): mixed
    {
        $before = $this->beforeTransaction;
        $this->beforeTransaction = null;
        if ($before !== null) {
            $before();
        }
        return parent::transactional($func);
    }
}

final class TelegramServiceStreamingTest extends TestCase
{
    private TelegramServiceTestConnection $sql;
    private TelegramService $service;
    private ChatStopRequests $stops;
    private SemanticMemoryRegistry $memory;
    private string $id;
    private array $sent = [];
    private array $states = [];

    private function setupService(
        FakeAIProvider $provider,
        array $tools = [],
        ?\App\Services\Queue\QueueDispatcherInterface $semanticQueue = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        bool $failDeliveryOnce = false,
    ): void
    {
        $this->sent = [];
        $this->states = [];
        $this->sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true,
            'wrapperClass' => TelegramServiceTestConnection::class]);
        require_once Settings::getAppRoot() . '/test/Support/TelegramSqlSchema.php';
        require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
        \App\Test\Support\TelegramSqlSchema::create($this->sql);
        \App\Test\Support\ChatTurnSqlSchema::create($this->sql);
        $this->sql->executeStatement('CREATE TABLE account (id VARCHAR(255) PRIMARY KEY)');
        $this->sql->insert('account', ['id' => 'owner']);
        foreach ([\Migrations\Version20260930000100::class, \Migrations\Version20260930000200::class,
            \Migrations\Version20260930000300::class] as $class) {
            $migration = new $class($this->sql, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->sql->executeStatement($query->getStatement());
            }
        }
        $this->id = TelegramJournal::id('123', 'update:42');
        $this->sql->insert('telegram_stop_target', ['id' => $this->id, 'bot_id' => '123', 'update_id' => 42,
            'user_id' => 'owner', 'thread_id' => 'frozen-thread', 'chat_id' => '42', 'topic_id' => 7]);
        $this->stops = new ChatStopRequests($this->sql);
        $this->stops->register('owner', 'frozen-thread', 'telegram', $this->id);
        $this->memory = new SemanticMemoryRegistry($this->sql, enabled: true);
        $this->memory->setEnabled('owner', true);
        $entity = new SessionEntity();
        $entity->setSessionData([Auth::AUTHENTICATED => true, Auth::USERID => 'owner',
            'brain_avatar' => 'test', 'threadId' => 'stale-thread']);
        $repository = $this->createStub(TelegramSessionRepository::class);
        $repository->method('findOrCreateByTelegramId')->willReturn($entity);
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->method('getConnection')->willReturn($this->sql);
        $session = new TelegramSession($manager);
        $session->load('42');
        $settings = new Settings(['telegram' => ['bot_token' => '123:synthetic'], 'redis' => ['prefix' => 'test:'],
            'queue' => ['defaultQueue' => 'served-default'],
            'llm' => ['brains' => ['test' => StoppedTelegramTestAgent::class], 'stop' => ['enabled' => true],
                'semanticMemory' => ['enabled' => true], 'yamlBrains' => ['path' => '/tmp/kilo/no-brains']]]);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $name): mixed => match ($name) {
            Connection::class => $this->sql,
            Settings::class => $settings,
            \Psr\Log\LoggerInterface::class => new NullLogger(),
            FakeAIProvider::class => $provider,
            'test.tools' => $tools,
            default => throw new \LogicException('Unexpected dependency ' . $name),
        });
        $registry = new BrainRegistry($settings, $container, new ThemeRegistry($settings));
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturnCallback(fn (string $key): array => $this->states[$key] ?? []);
        $redis->method('hset')->willReturnCallback(function (string $key, array $value): int {
            $this->states[$key] = $value;
            return 1;
        });
        $transport = $this->createStub(TransportInterface::class);
        $respond = function (string $url, array|string $parameters) use (&$failDeliveryOnce): ApiResponse {
            $method = basename($url);
            if (is_string($parameters)) {
                $parameters = json_decode($parameters, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($method === 'sendChatAction') {
                return new ApiResponse(200, '{"ok":true,"result":true}');
            }
            self::assertSame('sendMessage', $method);
            self::assertContains($this->sql->fetchOne('SELECT status FROM chat_turn'), ['succeeded', 'stopped', 'rolled_back']);
            if ($failDeliveryOnce) {
                $failDeliveryOnce = false;
                throw new \RuntimeException('Synthetic delivery failure');
            }
            $this->sent[] = $parameters;
            return new ApiResponse(200, '{"ok":true,"result":{"message_id":1,"date":1,"chat":{"id":42,"type":"private"}}}');
        };
        $transport->method('post')->willReturnCallback($respond);
        $transport->method('postWithFiles')->willReturnCallback($respond);
        $api = new TelegramBotApi('123:synthetic', transport: $transport);
        $audio = $this->createMock(AudioServiceInterface::class);
        $audio->expects(self::never())->method('speech');
        $audio->expects(self::never())->method('transcribe');
        $this->service = new \ReflectionClass(TelegramService::class)->newInstanceWithoutConstructor();
        foreach ([
            'logger' => $logger ?? new NullLogger(), 'settings' => $settings, 'brainRegistry' => $registry,
            'entityManager' => $manager, 'telegramSession' => $session, 'telegramBotApi' => $api,
            'audioService' => $audio, 'telegramAudioService' => new TelegramAudioService($api, $audio, new NullLogger()),
            'telegramMarkdown' => new TelegramMarkdown(),
            'telegramChatActionHeartbeat' => new TelegramChatActionHeartbeat($settings, new NullLogger(), static function (): void {}),
            'telegramGeneration' => new TelegramGeneration($settings, $this->sql, new ChatGenerationState($redis, $settings)),
            'semanticMemoryRegistry' => $this->memory, 'generationId' => 'update:42',
            'semanticQueue' => $semanticQueue,
        ] as $name => $value) {
            new \ReflectionProperty(TelegramService::class, $name)->setValue($this->service, $value);
        }
    }

    private function runGeneration(string|callable $text = 'Question', bool $voice = false): void
    {
        new \ReflectionMethod(TelegramService::class, 'processChatMessage')->invoke($this->service, 42, $text, $voice);
    }

    private function stop(): void
    {
        $this->stops->request('owner', 'frozen-thread', 'telegram', $this->id);
    }

    public function testSemanticDispatchAfterCommittedDeliveryIsBestEffortAndNotRepeated(): void
    {
        foreach (['success', 'enqueue-failure', 'stopped', 'no-consent'] as $scenario) {
            $queue = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);
            $dispatches = in_array($scenario, ['success', 'enqueue-failure'], true);
            $queue->expects($dispatches ? self::once() : self::never())->method('dispatch')
                ->willReturnCallback(function (string $job, array $payload, string $name) use ($scenario): string {
                    self::assertFalse($this->sql->isTransactionActive());
                    self::assertFalse($this->sql->getNativeConnection()->inTransaction());
                    self::assertSame('succeeded', $this->sql->fetchOne('SELECT status FROM chat_turn'));
                    self::assertTrue(new TelegramJournal($this->sql)->load($this->id)['delivered']);
                    self::assertCount(1, $this->sent);
                    $row = $this->sql->fetchAssociative('SELECT * FROM semantic_memory_excerpt');
                    self::assertSame('pending', $row['status']);
                    self::assertSame(\App\Job\SemanticMemory\IndexTurnJob::class, $job);
                    self::assertSame(['userId' => 'owner', 'documentId' => $row['id']], $payload);
                    self::assertSame('served-default', $name);
                    if ($scenario === 'enqueue-failure') {
                        throw new \RuntimeException('Sensitive queue credentials');
                    }
                    return 'synthetic-job';
                });
            $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
            $logger->expects($scenario === 'enqueue-failure' ? self::once() : self::never())->method('warning')
                ->with('Semantic indexing enqueue failed', ['error' => \RuntimeException::class]);
            $provider = new FakeAIProvider(new AssistantMessage('Answer'));
            $this->setupService($provider, semanticQueue: $queue, logger: $logger);
            if ($scenario === 'stopped') {
                $this->stop();
            } elseif ($scenario === 'no-consent') {
                $this->memory->setEnabled('owner', false);
            }
            $this->runGeneration();
            self::assertSame($scenario === 'stopped' ? 'stopped' : 'succeeded',
                $this->sql->fetchOne('SELECT status FROM chat_turn'));
            $this->runGeneration();
            self::assertSame($scenario === 'stopped' ? 0 : 1, $provider->getCallCount());
            self::assertCount(1, $this->sent);
            if ($dispatches) {
                self::assertSame('pending', $this->sql->fetchOne('SELECT status FROM semantic_memory_excerpt'));
            }
        }
    }

    public function testQueuedTextStopPersistsOnlyUserAndDeliversNoticeInFrozenTopic(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        $this->setupService($provider);
        $this->stop();
        $this->runGeneration(voice: true);
        self::assertSame(0, $provider->getCallCount());
        self::assertSame('stopped', $this->sql->fetchOne('SELECT status FROM chat_turn'));
        $display = json_decode($this->sql->fetchOne('SELECT display_messages FROM chat_history'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $display);
        self::assertSame('user', $display[0]['role']);
        self::assertSame('user-' . $this->id, $display[0]['__meta']['claire_submission_id']);
        self::assertSame(7, $this->sent[0]['message_thread_id']);
        self::assertSame('42', (string) $this->sent[0]['chat_id']);
        self::assertSame(0, (int) $this->sql->fetchOne("SELECT COUNT(*) FROM semantic_memory_excerpt WHERE status='pending'"));
        $this->runGeneration();
        self::assertCount(1, $this->sent);
    }

    public function testDeliveryFailureLeavesPendingOutboxForSweepWithoutDispatchOnRetry(): void
    {
        $queue = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);
        $queue->expects(self::never())->method('dispatch');
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $this->setupService($provider, semanticQueue: $queue, failDeliveryOnce: true);
        try {
            $this->runGeneration();
            self::fail('Delivery failure did not propagate');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic delivery failure', $error->getMessage());
        }
        self::assertSame('succeeded', $this->sql->fetchOne('SELECT status FROM chat_turn'));
        self::assertSame('pending', $this->sql->fetchOne('SELECT status FROM semantic_memory_excerpt'));
        $this->runGeneration();
        self::assertTrue(new TelegramJournal($this->sql)->load($this->id)['delivered']);
        self::assertSame(1, $provider->getCallCount());
    }

    public function testQueuedVoiceStopDoesNotTranscribeOrInventUserText(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        $this->setupService($provider);
        $this->stop();
        $this->runGeneration(static function (): string { self::fail('Queued voice was transcribed'); }, true);
        self::assertSame(0, $provider->getCallCount());
        self::assertSame('[]', $this->sql->fetchOne('SELECT display_messages FROM chat_history'));
        self::assertSame('stopped', $this->sql->fetchOne('SELECT status FROM chat_turn'));
    }

    public function testExhaustedQueuedStopKeepsOriginalTopicAndDoesNotRepeatNotice(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        $this->setupService($provider);
        $this->stop();
        $payload = ['update_json' => json_encode([
            'update_id' => 42,
            'message' => ['message_id' => 1, 'date' => 1, 'message_thread_id' => 99,
                'chat' => ['id' => 42, 'type' => 'supergroup'],
                'from' => ['id' => 42, 'is_bot' => false, 'first_name' => 'Synthetic'],
                'text' => 'Question'],
        ], JSON_THROW_ON_ERROR)];
        $this->service->failed($payload);
        self::assertSame('stopped', $this->sql->fetchOne('SELECT status FROM chat_turn'));
        self::assertSame(7, $this->sent[0]['message_thread_id']);
        self::assertSame(42, $this->sent[0]['chat_id']);
        self::assertSame(0, $provider->getCallCount());
        $display = json_decode($this->sql->fetchOne('SELECT display_messages FROM chat_history'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $display);
        self::assertSame('user', $display[0]['role']);
        $this->service->failed($payload);
        self::assertCount(1, $this->sent);
    }

    public function testFailedStopNotificationRetryUsesFrozenChatAndTopic(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        $this->setupService($provider, failDeliveryOnce: true);
        $this->stop();
        $update = ['update_id' => 42, 'message' => [
            'message_id' => 1, 'date' => 1, 'message_thread_id' => 7,
            'chat' => ['id' => 42, 'type' => 'supergroup'],
            'from' => ['id' => 42, 'is_bot' => false, 'first_name' => 'Synthetic'], 'text' => 'Question',
        ]];
        try {
            $this->service->failed(['update_json' => json_encode($update, JSON_THROW_ON_ERROR)]);
            self::fail('Notice delivery failure did not propagate');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic delivery failure', $error->getMessage());
        }
        $update['message']['chat']['id'] = 999;
        $update['message']['message_thread_id'] = 99;
        $this->service->failed(['update_json' => json_encode($update, JSON_THROW_ON_ERROR)]);
        self::assertSame(42, $this->sent[0]['chat_id']);
        self::assertSame(7, $this->sent[0]['message_thread_id']);
        self::assertSame(0, $provider->getCallCount());
        self::assertSame('stopped', $this->sql->fetchOne('SELECT status FROM chat_turn'));
    }

    public function testPartialStopRetainsTextAndRetriesDeliveryWithoutInference(): void
    {
        $provider = new class extends FakeAIProvider {
            public ?\Closure $stop = null;
            public int $calls = 0;

            public function stream(Message ...$messages): \Generator
            {
                $this->calls++;
                yield new TextChunk('partial', 'Partial');
                ($this->stop)();
                return new ProviderResponse(new AssistantMessage('Partial')->setId('partial')->setStopReason('stopped'));
            }
        };
        $this->setupService($provider);
        $provider->stop = $this->stop(...);
        $this->runGeneration(voice: true);
        self::assertSame(1, $provider->calls);
        self::assertSame('stopped', $this->sql->fetchOne('SELECT status FROM chat_turn'));
        self::assertStringContainsString('Partial', $this->sent[0]['text']);
        self::assertSame('Partial', new TelegramJournal($this->sql)->load($this->id)['response']);
        self::assertSame(0, (int) $this->sql->fetchOne("SELECT COUNT(*) FROM semantic_memory_excerpt WHERE status='pending'"));
        $this->runGeneration();
        self::assertSame(1, $provider->calls);
    }

    public function testStopInsideToolKeepsResultAndDoesNotRunSecondToolOrPostprocessing(): void
    {
        $counter = (object) ['calls' => 0, 'postprocess' => 0, 'stop' => null];
        $tool = new class($counter) extends Tool implements \App\Brain\Tools\MessagePostProcessorInterface {
            protected string $name = 'probe';
            public function __construct(private object $counter) {}
            public function __invoke(): string
            {
                $this->counter->calls++;
                ($this->counter->stop)();
                return 'completed';
            }
            public function postProcessMessage(Message $message, ToolCall $call): Message
            {
                $this->counter->postprocess++;
                return $message;
            }
        };
        $provider = new FakeAIProvider(new ToolCallMessage('Working', [ToolCall::make('probe', 'one'), ToolCall::make('probe', 'two')]),
            new AssistantMessage('Must not run'));
        $this->setupService($provider, [$tool]);
        $counter->stop = $this->stop(...);
        $this->runGeneration(voice: true);
        self::assertSame(1, $counter->calls);
        self::assertSame(0, $counter->postprocess);
        self::assertSame(1, $provider->getCallCount());
        self::assertStringContainsString('CANCELLED', $this->sql->fetchOne('SELECT stored_messages FROM chat_history'));
        self::assertSame('stopped', $this->sql->fetchOne('SELECT status FROM chat_turn'));
        $this->runGeneration();
        self::assertSame(1, $counter->calls);
    }

    public function testTerminalRaceSkipsSemanticAndSuccessfulTurnRecordsStableIds(): void
    {
        $provider = new class(new AssistantMessage('Answer')) extends FakeAIProvider {
            public ?\Closure $beforeFinish = null;
            public function stream(Message ...$messages): \Generator
            {
                $stream = parent::stream(...$messages);
                yield from $stream;
                ($this->beforeFinish)();
                return $stream->getReturn();
            }
        };
        $this->setupService($provider);
        $provider->beforeFinish = function (): void { $this->sql->beforeTransaction = $this->stop(...); };
        $this->runGeneration(voice: true);
        self::assertSame('stopped', $this->sql->fetchOne('SELECT status FROM chat_turn'));
        self::assertSame(0, (int) $this->sql->fetchOne("SELECT COUNT(*) FROM semantic_memory_excerpt WHERE status='pending'"));
    }

    public function testSuccessRecordsStableClaireIdsAndLateStopIsHarmless(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $this->setupService($provider);
        $this->runGeneration();
        self::assertSame('succeeded', $this->sql->fetchOne('SELECT status FROM chat_turn'));
        $excerpt = $this->sql->fetchAssociative('SELECT * FROM semantic_memory_excerpt');
        self::assertSame('pending', $excerpt['status']);
        self::assertSame(['user-' . $this->id, 'assistant-' . $this->id], json_decode($excerpt['source_ids'], true, flags: JSON_THROW_ON_ERROR));
        $this->stop();
        self::assertSame('succeeded', $this->sql->fetchOne('SELECT status FROM chat_stop_request'));
        $this->runGeneration();
        self::assertSame(1, $provider->getCallCount());
    }

    public function testProviderCutWithoutDurableStopRollsBackAndDoesNotReplay(): void
    {
        $provider = new class extends FakeAIProvider {
            public int $calls = 0;
            public function stream(Message ...$messages): \Generator
            {
                $this->calls++;
                yield new TextChunk('cut', 'Partial');
                throw new \NeuronAI\Exceptions\ProviderException('Missing closing event');
            }
        };
        $this->setupService($provider);
        try {
            $this->runGeneration();
            self::fail('Provider cut was classified as a user stop');
        } catch (\App\Services\Queue\NonRetryableJobException $error) {
            self::assertInstanceOf(\NeuronAI\Exceptions\ProviderException::class, $error->getPrevious());
        }
        self::assertSame('rolled_back', $this->sql->fetchOne('SELECT status FROM chat_turn'));
        self::assertSame('rolled_back', $this->sql->fetchOne('SELECT status FROM chat_stop_request'));
        self::assertSame('[]', $this->sql->fetchOne('SELECT display_messages FROM chat_history'));
        self::assertSame(0, (int) $this->sql->fetchOne("SELECT COUNT(*) FROM semantic_memory_excerpt WHERE status='pending'"));
        $this->runGeneration();
        self::assertSame(1, $provider->calls);
    }
}
