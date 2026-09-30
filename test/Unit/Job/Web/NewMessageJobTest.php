<?php

declare(strict_types=1);

namespace App\Test\Unit\Job\Web;

use App\Brain\Agent;
use App\Brain\BrainAvatar;
use App\Brain\BrainRegistry;
use App\Services\ThemeRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Job\Web\NewMessageJob;
use App\Renderer\ChatDataRenderer;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\ChatAudioPublisher;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\ChatThreadLock;
use App\Services\RedisClient;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Settings;
use App\Services\Session\SessionInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

interface StreamFixture
{
    public function events(): \Generator;
    public function getMessage(): Message;
}

final class StreamingJobTestAgent extends Agent implements BrainAvatar
{
    public const string NAME = 'Test';
    public const string DESCRIPTION = 'Test';
    public const string AVATAR = '';
    public const string THEME = 'cyberpunk';

    public function __construct(
        private ContainerInterface $testContainer,
        SessionInterface $session,
        ?string $threadId,
    ) {
        parent::__construct($testContainer, $session, $threadId);
        $this->setTools($testContainer->get('test.tools'));
        $fixture = $testContainer->get(StreamFixture::class);
        $this->setAiProvider($fixture instanceof FakeAIProvider ? $fixture : new class($fixture) extends FakeAIProvider {
            public function __construct(private StreamFixture $fixture)
            {
                parent::__construct();
            }

            public function stream(Message ...$messages): \Generator
            {
                yield from $this->fixture->events();
                return new ProviderResponse($this->fixture->getMessage()->setId('provider-id'));
            }
        });
    }

    protected function middleware(): array
    {
        return [\NeuronAI\Agent\Nodes\AgentEndNode::class => [
            new \App\Brain\Middleware\ToolCalls($this->getUserChatHistory()),
        ]];
    }

    protected function resolveTools(): array
    {
        return [new \NeuronAI\Chat\Messages\SystemMessage('test'), $this->getTools()];
    }
}

final class StopRaceTestConnection extends Connection
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

final class NewMessageJobTest extends TestCase
{
    public function testSemanticIndexDispatchIsPostCommitAndFailureLeavesSuccessfulOutbox(): void
    {
        foreach (['success', 'enqueue-failure', 'stopped', 'no-consent'] as $scenario) {
            $sql = null;
            $warnings = [];
            $events = [];
            $queue = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);
            $dispatches = in_array($scenario, ['success', 'enqueue-failure'], true);
            $queue->expects($dispatches ? self::once() : self::never())->method('dispatch')
                ->willReturnCallback(static function (string $job, array $payload, string $name) use (&$sql, $scenario): string {
                    self::assertFalse($sql->isTransactionActive());
                    self::assertFalse($sql->getNativeConnection()->inTransaction());
                    self::assertSame('succeeded', $sql->fetchOne('SELECT status FROM chat_turn'));
                    $row = $sql->fetchAssociative('SELECT * FROM semantic_memory_excerpt');
                    self::assertSame('pending', $row['status']);
                    self::assertSame(\App\Job\SemanticMemory\IndexTurnJob::class, $job);
                    self::assertSame(['userId' => 'user-1', 'documentId' => $row['id']], $payload);
                    self::assertSame('served-default', $name);
                    if ($scenario === 'enqueue-failure') {
                        throw new \RuntimeException('Sensitive queue connection details');
                    }
                    return 'synthetic-job';
                });
            $provider = new FakeAIProvider(new AssistantMessage('Answer'));
            [$job, , , $sql, $memory] = $this->job($provider,
                static function (array $event) use (&$events): void { $events[] = $event['event']; },
                stopEnabled: true, semanticEnabled: true, semanticQueue: $queue,
                onWarning: static function (string $message, array $context) use (&$warnings): void {
                    if ($message === 'Semantic indexing enqueue failed') {
                        $warnings[] = [$message, $context];
                    }
                });
            if ($scenario !== 'no-consent') {
                $memory->setEnabled('user-1', true);
            }
            $stops = new \App\Services\ChatStopRequests($sql);
            $stops->register('user-1', $scenario, 'web', 'message-' . $scenario);
            if ($scenario === 'stopped') {
                $stops->request('user-1', $scenario, 'web', 'message-' . $scenario);
            }
            $job->handle($this->payload($scenario));
            self::assertSame($scenario === 'stopped' ? 'stopped' : 'succeeded',
                $sql->fetchOne('SELECT status FROM chat_turn'));
            if ($scenario !== 'stopped') {
                self::assertContains('chat.assistant.done', $events);
            }
            self::assertSame($scenario === 'enqueue-failure'
                ? [['Semantic indexing enqueue failed', ['error' => \RuntimeException::class]]] : [], $warnings);
            $job->handle($this->payload($scenario));
            self::assertSame($scenario === 'stopped' ? 0 : 1, $provider->getCallCount());
            if ($dispatches) {
                self::assertSame('pending', $sql->fetchOne('SELECT status FROM semantic_memory_excerpt'));
            }
        }
    }

    public function testQueuedStopPersistsUserOnlyAndAcknowledgesWithoutInferenceOrSemanticIndexing(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        $events = [];
        [$job, $publisher, , $sql, $memory] = $this->job($provider,
            static function (array $event) use (&$events): void { $events[] = $event; },
            static function (): void { self::fail('Stopped generation summarized'); },
            stopEnabled: true, semanticEnabled: true);
        $memory->setEnabled('user-1', true);
        $stops = new \App\Services\ChatStopRequests($sql);
        $stops->register('user-1', 'queued-stop', 'web', 'message-queued-stop');
        $stops->request('user-1', 'queued-stop', 'web', 'message-queued-stop');
        $job->handle($this->payload('queued-stop'));
        self::assertSame(0, $provider->getCallCount());
        self::assertSame('stopped', $sql->fetchOne('SELECT status FROM chat_turn'));
        self::assertSame('stopped', $publisher->generationState()->get('user-1', 'queued-stop')['status']);
        $display = json_decode($sql->fetchOne('SELECT display_messages FROM chat_history'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $display);
        self::assertSame('user', $display[0]['role']);
        self::assertSame(0, (int) $sql->fetchOne("SELECT COUNT(*) FROM semantic_memory_excerpt WHERE status = 'pending'"));
        self::assertContains('chat.assistant.stopped', array_column($events, 'event'));
        self::assertNotContains('chat.assistant.done', array_column($events, 'event'));
        $job->handle($this->payload('queued-stop'));
        self::assertSame(0, $provider->getCallCount());
        self::assertSame('stopped', $sql->fetchOne('SELECT status FROM chat_stop_request'));
    }

    public function testStopBetweenChunksRetainsPartialAndSkipsAudioAndSemanticIndexing(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First second third'));
        $sql = null;
        $events = [];
        $audio = $this->createMock(AudioServiceInterface::class);
        $audio->expects(self::never())->method('speech');
        [$job, , , $sql, $memory] = $this->job($provider,
            static function (array $event) use (&$sql, &$events): void {
                $events[] = $event;
                if ($event['event'] === 'chat.assistant.update') {
                    new \App\Services\ChatStopRequests($sql)->request('user-1', 'partial-stop', 'web', 'message-partial-stop');
                }
            }, static function (): void { self::fail('Stopped generation summarized'); }, $audio,
            stopEnabled: true, semanticEnabled: true);
        $memory->setEnabled('user-1', true);
        new \App\Services\ChatStopRequests($sql)->register('user-1', 'partial-stop', 'web', 'message-partial-stop');
        $payload = $this->payload('partial-stop');
        $payload['session'][AudioServiceInterface::AUTO_GENERATE_SESSION_KEY] = true;
        $job->handle($payload);
        self::assertSame(1, $provider->getCallCount());
        self::assertSame('stopped', $sql->fetchOne('SELECT status FROM chat_turn'));
        $display = json_decode($sql->fetchOne('SELECT display_messages FROM chat_history'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $display);
        self::assertSame('First', $display[1]['content'][0]['content']);
        self::assertSame('message-partial-stop', $display[1]['__meta']['claire_message_id']);
        self::assertNull($display[1]['__meta']['claire_audio_request_id'] ?? null);
        self::assertSame(0, (int) $sql->fetchOne("SELECT COUNT(*) FROM semantic_memory_excerpt WHERE status = 'pending'"));
        self::assertNotContains('chat.assistant.done', array_column($events, 'event'));
    }

    public function testQueuedStopCommitsAndAcknowledgesWhenRedisIsUnavailable(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        [$job, , , $sql] = $this->job($provider, stopEnabled: true, redisUnavailable: true);
        $stops = new \App\Services\ChatStopRequests($sql);
        $stops->register('user-1', 'offline-stop', 'web', 'message-offline-stop');
        $stops->request('user-1', 'offline-stop', 'web', 'message-offline-stop');
        $job->handle($this->payload('offline-stop'));
        self::assertSame(0, $provider->getCallCount());
        self::assertSame('stopped', $sql->fetchOne('SELECT status FROM chat_turn'));
        self::assertSame('stopped', $sql->fetchOne('SELECT status FROM chat_stop_request'));
        self::assertSame(1, (int) $sql->fetchOne('SELECT display_messages_count FROM chat_history'));
        $job->handle($this->payload('offline-stop'));
        self::assertSame(0, $provider->getCallCount());
    }

    public function testTerminalAcceptanceWithoutTurnCannotAuthorizeInference(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        [$job, , , $sql] = $this->job($provider, stopEnabled: true);
        $stops = new \App\Services\ChatStopRequests($sql);
        $stops->register('user-1', 'rejected', 'web', 'message-rejected');
        $sql->transactional(static fn () => $stops->arbitrateTerminal(
            'user-1', 'rejected', 'web', 'message-rejected', 'rolled_back'));
        $job->handle($this->payload('rejected'));
        self::assertSame(0, $provider->getCallCount());
        self::assertSame(0, (int) $sql->fetchOne('SELECT COUNT(*) FROM chat_turn'));
    }

    public function testStopInsideToolPreservesCompletedResultAndCancelsNextToolWithoutReplay(): void
    {
        $counter = (object) ['calls' => 0, 'postprocess' => 0, 'onCall' => null];
        $provider = new FakeAIProvider(
            new \NeuronAI\Chat\Messages\ToolCallMessage('Working. ', [
                ToolCall::make('probe', 'first'), ToolCall::make('probe', 'second'),
            ]),
            new AssistantMessage('Must not run'),
        );
        [$job, , , $sql] = $this->job($provider, tools: [$this->probeTool($counter)], stopEnabled: true);
        $stops = new \App\Services\ChatStopRequests($sql);
        $stops->register('user-1', 'tool-stop', 'web', 'message-tool-stop');
        $counter->onCall = static fn () => $stops->request('user-1', 'tool-stop', 'web', 'message-tool-stop');
        $job->handle($this->payload('tool-stop'));
        self::assertSame(1, $counter->calls);
        self::assertSame(0, $counter->postprocess);
        self::assertSame(1, $provider->getCallCount());
        self::assertSame('stopped', $sql->fetchOne('SELECT status FROM chat_turn'));
        $stored = $sql->fetchOne('SELECT stored_messages FROM chat_history');
        self::assertStringContainsString('CANCELLED', $stored);
        self::assertStringContainsString('result', $stored);
        $job->handle($this->payload('tool-stop'));
        self::assertSame(1, $counter->calls);
        $next = $this->payload('tool-stop');
        $next['messageId'] = 'message-tool-stop-next';
        $stops->register('user-1', 'tool-stop', 'web', $next['messageId']);
        $publisher = new \ReflectionProperty($job, 'chatStreamPublisher')->getValue($job);
        $publisher->generationState()->set('user-1', 'tool-stop', $next['messageId'], 'queued', false);
        $job->handle($next);
        self::assertSame('succeeded', $sql->fetchOne('SELECT status FROM chat_turn WHERE id = ?', [$next['messageId']]));
        self::assertSame(1, $counter->calls);
        self::assertSame(2, $provider->getCallCount());
    }

    public function testStopWinningFinalTransactionSuppressesSuccessAndSemanticRecord(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        $sql = null;
        [$job, , , $sql, $memory] = $this->job($provider,
            static function (array $event) use (&$sql): void {
                if ($event['event'] === 'chat.assistant.update' && $sql->fetchOne('SELECT status FROM chat_turn') === 'running') {
                    $sql->beforeTransaction = static fn () => new \App\Services\ChatStopRequests($sql)
                        ->request('user-1', 'race-stop', 'web', 'message-race-stop');
                }
            }, static function (): void { self::fail('Race stopped generation summarized'); },
            connectionClass: StopRaceTestConnection::class, stopEnabled: true, semanticEnabled: true);
        $memory->setEnabled('user-1', true);
        new \App\Services\ChatStopRequests($sql)->register('user-1', 'race-stop', 'web', 'message-race-stop');
        $job->handle($this->payload('race-stop'));
        self::assertSame('stopped', $sql->fetchOne('SELECT status FROM chat_turn'));
        self::assertSame(1, $provider->getCallCount());
        self::assertSame(0, (int) $sql->fetchOne("SELECT COUNT(*) FROM semantic_memory_excerpt WHERE status = 'pending'"));
    }

    public function testSuccessfulConsentCaptureUsesPersistedClaireIdsAndLateStopLoses(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Answer'));
        [$job, , , $sql, $memory] = $this->job($provider, stopEnabled: true, semanticEnabled: true);
        $memory->setEnabled('user-1', true);
        $stops = new \App\Services\ChatStopRequests($sql);
        $stops->register('user-1', 'semantic', 'web', 'message-semantic');
        $payload = $this->payload('semantic');
        $payload['submissionId'] = 'submission-semantic';
        $job->handle($payload);
        self::assertSame('succeeded', $sql->fetchOne('SELECT status FROM chat_turn'));
        $excerpt = $sql->fetchAssociative('SELECT * FROM semantic_memory_excerpt');
        self::assertSame('pending', $excerpt['status']);
        self::assertSame(['submission-semantic', 'message-semantic'], json_decode($excerpt['source_ids'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame("User: Question\nAssistant: Answer", $excerpt['content']);
        self::assertSame('succeeded', $stops->request('user-1', 'semantic', 'web', 'message-semantic')['status']);
        $job->handle($payload);
        self::assertSame(1, $provider->getCallCount());
        self::assertSame(1, (int) $sql->fetchOne('SELECT COUNT(*) FROM semantic_memory_excerpt'));
    }

    public function testRealAgentToolCyclesRunOnceAndKeepPostprocessedFinalText(): void
    {
        $counter = (object) ['calls' => 0];
        $tool = $this->probeTool($counter);
        $provider = new FakeAIProvider(
            new \NeuronAI\Chat\Messages\ToolCallMessage('First. ', [ToolCall::make('probe', 'first')]),
            new \NeuronAI\Chat\Messages\ToolCallMessage('Second. ', [ToolCall::make('probe', 'second')]),
            new AssistantMessage('Answer'),
        );
        $events = [];
        [$job, , $pdo] = $this->job($provider, static function (array $event) use (&$events): void {
            $events[] = $event;
        }, tools: [$tool]);
        $job->handle($this->payload('real-tools'));
        self::assertSame(2, $counter->calls);
        self::assertSame(3, $provider->getCallCount());
        $updates = array_values(array_filter($events,
            static fn (array $event): bool => $event['event'] === 'chat.assistant.update'));
        self::assertSame('First. Second. Answer [first] [second]', $updates[array_key_last($updates)]['payload']['message']);
        $display = json_decode($pdo->query('SELECT display_messages FROM chat_history')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString(' [first] [second]', json_encode($display, JSON_THROW_ON_ERROR));
        $job->handle($this->payload('real-tools'));
        self::assertSame(2, $counter->calls);
        self::assertSame(3, $provider->getCallCount());
    }

    public function testRealAgentCannotExecuteToolAfterPublicationLosesLock(): void
    {
        $counter = (object) ['calls' => 0];
        $provider = new FakeAIProvider(
            new \NeuronAI\Chat\Messages\ToolCallMessage('Before. ', [ToolCall::make('probe', 'first')]),
            new AssistantMessage('Must not infer'),
        );
        $lock = new ChatThreadLock(new \PDO('sqlite::memory:'), 'user-1', 'real-lock');
        [$job] = $this->job($provider, static function (array $event) use ($lock): void {
            if ($event['event'] === 'chat.tool.update') {
                $lock->release();
            }
        }, tools: [$this->probeTool($counter)]);
        $payload = $this->payload('real-lock');
        new \ReflectionMethod($job, 'initContext')->invoke($job, $payload);
        $registry = new \ReflectionProperty($job, 'brainRegistry')->getValue($job);
        $agent = $registry->get('test', new \App\Services\Session\InMemorySession($payload['session']), 'real-lock');
        new \ReflectionProperty($job, 'agent')->setValue($job, $agent);
        try {
            new \ReflectionMethod($job, 'processChatStream')->invoke($job, $lock);
            self::fail('Resumed after losing lock');
        } catch (\RuntimeException $exception) {
            self::assertSame('Chat lock was released', $exception->getMessage());
        } finally {
            $lock->release();
        }
        self::assertSame(0, $counter->calls);
        self::assertSame(1, $provider->getCallCount());
    }

    public function testSuspensionAndStoppedProviderAreNeverCommittedAsSuccess(): void
    {
        foreach (['suspension', 'stopped'] as $scenario) {
            $counter = (object) ['calls' => 0];
            $tool = $this->probeTool($counter)->requireApproval();
            $response = $scenario === 'suspension'
                ? new \NeuronAI\Chat\Messages\ToolCallMessage('Waiting', [ToolCall::make('probe', 'approval')])
                : new AssistantMessage('Partial')->setStopReason('stopped');
            $provider = new FakeAIProvider($response);
            $events = [];
            [$job, , $pdo] = $this->job($provider, static function (array $event) use (&$events): void {
                $events[] = $event['event'];
            }, tools: [$tool]);
            try {
                $job->handle($this->payload($scenario));
                self::fail('Non-success state was accepted');
            } catch (\App\Services\Queue\NonRetryableJobException $exception) {
                self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
                self::assertStringContainsString($scenario === 'suspension' ? 'suspension' : 'Stopped',
                    $exception->getPrevious()->getMessage());
            }
            self::assertSame('rolled_back', $pdo->query('SELECT status FROM chat_turn')->fetchColumn());
            self::assertNotContains('chat.assistant.done', $events);
            self::assertSame(0, $counter->calls);
            self::assertSame(1, $provider->getCallCount());
        }
    }

    public function testLockIsCheckedBeforeFirstGeneratorExecution(): void
    {
        $handler = $this->createMock(StreamFixture::class);
        $handler->expects(self::never())->method('events');
        [$job] = $this->job($handler);
        new \ReflectionMethod($job, 'initContext')->invoke($job, $this->payload('first-lock'));
        $agent = $this->createMock(Agent::class);
        $agent->expects(self::never())->method('stream');
        new \ReflectionProperty($job, 'agent')->setValue($job, $agent);
        $lock = new ChatThreadLock(new \PDO('sqlite::memory:'), 'user-1', 'first-lock');
        $lock->release();
        $this->expectExceptionMessage('Chat lock was released');
        new \ReflectionMethod($job, 'processChatStream')->invoke($job, $lock);
    }

    public function testProviderFailureAfterPartialTextRollsBackWithoutRetryingInference(): void
    {
        $provider = new class extends FakeAIProvider {
            public int $calls = 0;

            public function stream(Message ...$messages): \Generator
            {
                $this->calls++;
                yield new TextChunk('partial-id', 'Partial');
                throw new \NeuronAI\Exceptions\ProviderException('The stream ended before the answer was complete.');
            }
        };
        $events = [];
        [$job, , $pdo, $sql] = $this->job($provider, static function (array $event) use (&$events): void {
            $events[] = $event['event'];
        }, stopEnabled: true);
        new \App\Services\ChatStopRequests($sql)->register('user-1', 'truncated', 'web', 'message-truncated');
        $payload = $this->payload('truncated');
        try {
            $job->handle($payload);
            self::fail('Truncated provider answer was accepted');
        } catch (\App\Services\Queue\NonRetryableJobException $exception) {
            self::assertInstanceOf(\NeuronAI\Exceptions\ProviderException::class, $exception->getPrevious());
        }
        self::assertContains('chat.assistant.update', $events);
        self::assertContains('chat.error', $events);
        self::assertNotContains('chat.assistant.done', $events);
        self::assertSame('rolled_back', $pdo->query('SELECT status FROM chat_turn')->fetchColumn());
        $job->handle($payload);
        self::assertSame(1, $provider->calls);
    }

    private function probeTool(object $counter): \NeuronAI\Tools\Tool
    {
        return new class($counter) extends \NeuronAI\Tools\Tool implements \App\Brain\Tools\MessagePostProcessorInterface {
            protected string $name = 'probe';
            protected ?string $description = 'Deterministic test tool';

            public function __construct(private object $counter)
            {
            }

            public function __invoke(): string
            {
                $this->counter->calls++;
                if (($this->counter->onCall ?? null) instanceof \Closure) {
                    ($this->counter->onCall)();
                }
                return 'result';
            }

            public function postProcessMessage(Message $message, ToolCall $call): Message
            {
                $this->counter->postprocess = ($this->counter->postprocess ?? 0) + 1;
                return $message->setContents($message->getContent() . ' [' . $call->getCallId() . ']');
            }
        };
    }

    public function testLockLostDuringToolPublicationStopsBeforeExecutingTheTool(): void
    {
        $toolCalls = 0;
        $handler = $this->createStub(StreamFixture::class);
        $handler->method('events')->willReturnCallback(static function () use (&$toolCalls): \Generator {
            yield new ToolCallChunk('provider-id', ToolCall::make('probe', 'call-probe'));
            $toolCalls++;
        });
        $lock = new ChatThreadLock(new \PDO('sqlite::memory:'), 'user-1', 'lost-tool-lock');
        [$job] = $this->job($handler, static function (array $event) use ($lock): void {
            if ($event['event'] === 'chat.tool.update') {
                $lock->release();
            }
        });
        new \ReflectionMethod($job, 'initContext')->invoke($job, $this->payload('lost-tool-lock'));
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturnCallback($handler->events(...));
        new \ReflectionProperty($job, 'agent')->setValue($job, $agent);
        try {
            new \ReflectionMethod($job, 'processChatStream')->invoke($job, $lock);
            self::fail('The generator resumed after losing the lock');
        } catch (\RuntimeException $exception) {
            self::assertSame('Chat lock was released', $exception->getMessage());
        } finally {
            $lock->release();
        }
        self::assertSame(0, $toolCalls);
    }

    public function testSummaryAndIdentityStayLockedButCompletionAndAudioDoNot(): void
    {
        $handler = $this->createStub(StreamFixture::class);
        $handler->method('events')->willReturnCallback(static function (): \Generator {
            yield new TextChunk('provider-id', 'Answer');
        });
        $handler->method('getMessage')->willReturn(new AssistantMessage('Answer'));
        $summarySeen = false;
        $doneSeen = false;
        $audioSeen = false;
        $publisher = $pdo = null;
        $audio = $this->createMock(AudioServiceInterface::class);
        $audio->method('isAvailable')->willReturn(true);
        $audio->method('isAllowedVoice')->willReturn(true);
        $audio->expects(self::once())->method('speech')->willReturnCallback(
            static function () use (&$doneSeen, &$audioSeen): \App\Services\Audio\SpeechResult {
                self::assertTrue($doneSeen);
                $lock = new ChatThreadLock(new \PDO('sqlite::memory:'), 'user-1', 'completion-test');
                $lock->release();
                $audioSeen = true;
                return new \App\Services\Audio\SpeechResult('audio', 'audio/mpeg', 'mp3');
            },
        );
        [$job, $publisher, $pdo] = $this->job(
            $handler,
            static function (array $event) use (&$summarySeen, &$doneSeen, &$publisher, &$pdo): void {
                if ($event['event'] === 'chat.assistant.done') {
                    self::assertSame('auto-message-completion-test', $event['payload']['audioRequestId']);
                    self::assertTrue($summarySeen);
                    self::assertFalse($publisher->generationState()->snapshot('user-1', 'completion-test')['responding']);
                    $lock = new ChatThreadLock(new \PDO('sqlite::memory:'), 'user-1', 'completion-test');
                    $lock->release();
                    $doneSeen = true;
                }
                if ($event['event'] === 'chat.audio.ready') {
                    self::assertTrue($doneSeen);
                    self::assertSame('auto-message-completion-test', $event['payload']['audioRequestId']);
                    $history = new UserChatHistory(new \App\Services\Session\InMemorySession([
                        Auth::USERID => 'user-1',
                    ]), $pdo, threadId: 'completion-test', createIfMissing: false);
                    self::assertSame($event['payload']['messageId'], $history->getFormattedMessages()[1]['id']);
                }
            },
            static function () use (&$summarySeen, &$doneSeen, &$publisher, &$pdo): void {
                self::assertFalse($doneSeen);
                self::assertSame('succeeded', $pdo->query('SELECT status FROM chat_turn')->fetchColumn());
                self::assertTrue($publisher->generationState()->snapshot('user-1', 'completion-test')['responding']);
                try {
                    new ChatThreadLock(new \PDO('sqlite::memory:'), 'user-1', 'completion-test');
                    self::fail('Summary executed without the mutation lock');
                } catch (\RuntimeException $exception) {
                    self::assertSame('Chat thread is busy', $exception->getMessage());
                }
                $display = json_decode($pdo->query('SELECT display_messages FROM chat_history')->fetchColumn(),
                    true, flags: JSON_THROW_ON_ERROR);
                    self::assertSame('message-completion-test', $display[1]['__meta']['claire_message_id']);
                    self::assertSame('auto-message-completion-test',
                        $display[1]['__meta'][UserChatHistory::AUDIO_REQUEST_ID_METADATA]);
                $summarySeen = true;
            },
            $audio,
        );
        $payload = $this->payload('completion-test');
        $payload['session'][AudioServiceInterface::AUTO_GENERATE_SESSION_KEY] = true;
        $payload['session'][AudioServiceInterface::ENABLED_SESSION_KEY] = true;
        $job->handle($payload);
        self::assertTrue($summarySeen);
        self::assertTrue($doneSeen);
        self::assertTrue($audioSeen);
    }

    public function testFinalPublicationFailureKeepsSuccessAndCannotReplayGeneration(): void
    {
        foreach (['chat.assistant.update', 'chat.assistant.done'] as $failedEvent) {
            $handler = $this->createMock(StreamFixture::class);
            $handler->expects(self::once())->method('events')->willReturnCallback(static function (): \Generator {
                yield from [];
            });
            $handler->method('getMessage')->willReturn(new AssistantMessage('Durable answer'));
            $failure = new \RuntimeException('SSE unavailable');
            $events = [];
            [$job, $publisher, $pdo] = $this->job($handler,
                static function (array $event) use ($failure, $failedEvent, &$events): void {
                    $events[] = $event['event'];
                    if ($event['event'] === $failedEvent) {
                        $lock = new ChatThreadLock(new \PDO('sqlite::memory:'), 'user-1', 'delivery-test');
                        $lock->release();
                        throw $failure;
                    }
                });
            $payload = $this->payload('delivery-test');
            try {
                $job->handle($payload);
                self::fail('Publication failure swallowed');
            } catch (\RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            self::assertSame('done', $publisher->generationState()->get('user-1', 'delivery-test')['status']);
            self::assertNotContains('chat.error', $events);
            $history = new UserChatHistory(new \App\Services\Session\InMemorySession([
                Auth::USERID => 'user-1',
            ]), $pdo, threadId: 'delivery-test', createIfMissing: false);
            self::assertSame('message-delivery-test', $history->getFormattedMessages()[1]['id']);
            $job->handle($payload);
        }
    }

    public function testAutoAudioDecisionIsReusedAfterIdentityPersistence(): void
    {
        $handler = $this->createStub(StreamFixture::class);
        $handler->method('events')->willReturnCallback(static function (): \Generator {
            yield from [];
        });
        $handler->method('getMessage')->willReturn(new AssistantMessage('Answer'));
        $audio = $this->createMock(AudioServiceInterface::class);
        $audio->method('isAvailable')->willReturn(true);
        $audio->expects(self::once())->method('speech')
            ->willReturn(new \App\Services\Audio\SpeechResult('audio', 'audio/mpeg', 'mp3'));
        $events = [];
        $job = null;
        [$job] = $this->job($handler,
            static function (array $event) use (&$events, &$job): void {
                if ($event['event'] === 'chat.assistant.done') {
                    new \ReflectionProperty($job, 'inMemorySession')->getValue($job)
                        ->set(AudioServiceInterface::AUTO_GENERATE_SESSION_KEY, false);
                }
                if (in_array($event['event'], ['chat.assistant.done', 'chat.audio.ready'], true)) {
                    $events[$event['event']] = $event['payload']['audioRequestId'];
                }
            }, audio: $audio);
        $payload = $this->payload('stable-auto');
        $payload['session'][AudioServiceInterface::AUTO_GENERATE_SESSION_KEY] = true;
        $payload['session'][AudioServiceInterface::ENABLED_SESSION_KEY] = true;
        $job->handle($payload);
        self::assertSame([
            'chat.assistant.done' => 'auto-message-stable-auto',
            'chat.audio.ready' => 'auto-message-stable-auto',
        ], $events);
    }

    public function testStartPrecedesEveryUpdateIncludingAStreamWithoutChunks(): void
    {
        foreach ([true, false] as $withChunks) {
            $handler = $this->createStub(StreamFixture::class);
            $handler->method('events')->willReturnCallback(static function () use ($withChunks): \Generator {
                if ($withChunks) {
                    yield new TextChunk('provider-id', 'Answer');
                }
            });
            $handler->method('getMessage')->willReturn(new AssistantMessage('Answer'));
            $events = [];
            [$job] = $this->job($handler, static function (array $event) use (&$events): void {
                $events[] = $event;
            });
            $job->handle($this->payload('order-test'));
            $names = array_column($events, 'event');
            self::assertSame('chat.assistant.start', $names[0]);
            self::assertSame('chat.assistant.done', $names[count($names) - 1]);
            self::assertArrayHasKey('audioRequestId', $events[count($events) - 1]['payload']);
            self::assertNull($events[count($events) - 1]['payload']['audioRequestId']);
            self::assertContains('chat.assistant.update', $names);
            foreach ($events as $event) {
                self::assertSame('message-order-test', $event['payload']['messageId']);
                self::assertArrayNotHasKey('html', $event['payload']);
                if ($event['event'] === 'chat.assistant.update') {
                    self::assertSame('Answer', $event['payload']['message']);
                    self::assertSame([], $event['payload']['files']);
                }
            }
            if ($withChunks) {
                self::assertSame('chat.assistant.placeholder', $names[1]);
            }
        }
    }

    public function testCompletionPreservesTextAcrossMultipleToolRounds(): void
    {
        foreach (['Answer', ''] as $finalText) {
            $handler = $this->createStub(StreamFixture::class);
            $handler->method('events')->willReturnCallback(static function () use ($finalText): \Generator {
                foreach (['First. ', 'Second. '] as $index => $text) {
                    yield new TextChunk('tool-round-' . $index, $text);
                    $tool = ToolCall::make('search', 'call-' . $index);
                    yield new ToolCallChunk('tool-round-' . $index, $tool);
                    $tool->setResult('Result');
                    yield new ToolResultChunk($tool);
                }
                yield new TextChunk('provider-id', $finalText);
            });
            $handler->method('getMessage')->willReturn(new AssistantMessage($finalText));
            $events = [];
            [$job] = $this->job($handler, static function (array $event) use (&$events): void {
                $events[] = $event;
            });
            $job->handle($this->payload('multi-tool'));
            $updates = array_values(array_filter($events,
                static fn (array $event): bool => $event['event'] === 'chat.assistant.update'));
            self::assertSame('First. Second. ' . $finalText, $updates[array_key_last($updates)]['payload']['message']);
            $tools = array_values(array_filter($events,
                static fn (array $event): bool => $event['event'] === 'chat.tool.update'));
            self::assertCount(2, $tools[array_key_last($tools)]['payload']['toolsCall']);
        }
    }

    public function testSingletonIsResetBetweenJobsAndCompletedDeliveryIsNotReplayed(): void
    {
        $handler = $this->createMock(StreamFixture::class);
        $handler->expects(self::exactly(2))->method('events')->willReturnCallback(static function (): \Generator {
            yield new TextChunk('provider-id', 'Fresh answer');
        });
        $handler->method('getMessage')->willReturn(new AssistantMessage('Fresh answer'));
        [$job, $publisher] = $this->job($handler);
        $payload = $this->payload('first');
        $job->handle($payload);
        $job->handle($payload); // A redelivery must not enter the agent again.

        foreach (['streamedText' => 'LEAK', 'toolsCall' => ['old' => []], 'nbPublishedChunks' => 55,
            'attachments' => [['content' => 'old']], 'autoAudioRequestId' => 'auto-previous'] as $property => $value) {
            new \ReflectionProperty($job, $property)->setValue($job, $value);
        }
        $job->handle($this->payload('second'));
        self::assertSame('Fresh answer', new \ReflectionProperty($job, 'streamedText')->getValue($job));
        self::assertSame([], new \ReflectionProperty($job, 'toolsCall')->getValue($job));
        self::assertSame(1, new \ReflectionProperty($job, 'nbPublishedChunks')->getValue($job));
        self::assertNull(new \ReflectionProperty($job, 'attachments')->getValue($job));
        self::assertNull(new \ReflectionProperty($job, 'autoAudioRequestId')->getValue($job));
        self::assertSame(['responding' => false, 'activeMessageId' => null],
            $publisher->generationState()->snapshot('user-1', 'second'));
    }

    public function testStreamFailurePropagatesAndRetryCannotReplayTools(): void
    {
        $failure = new \RuntimeException('Provider disconnected');
        $handler = $this->createMock(StreamFixture::class);
        $handler->expects(self::once())->method('events')->willThrowException($failure);
        $events = [];
        [$job, $publisher, $pdo] = $this->job($handler, static function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $payload = $this->payload('thread');
        $payload['submissionId'] = 'submission-failed';
        try {
            $job->handle($payload);
            self::fail('Failure was swallowed');
        } catch (\RuntimeException $exception) {
            self::assertInstanceOf(\App\Services\Queue\NonRetryableJobException::class, $exception);
            self::assertSame($failure, $exception->getPrevious());
        }
        self::assertSame('error', $publisher->generationState()->get('user-1', 'thread')['status']);
        self::assertSame('[]', $pdo->query('SELECT messages FROM chat_history')->fetchColumn());
        self::assertSame('[]', $pdo->query('SELECT display_messages FROM chat_history')->fetchColumn());
        self::assertSame('rolled_back', $pdo->query('SELECT status FROM chat_turn')->fetchColumn());
        self::assertSame('submission-failed', $events[count($events) - 1]['payload']['submissionId']);
        self::assertTrue($events[count($events) - 1]['payload']['rollbackConfirmed']);
        $job->handle($payload);
    }

    public function testSubmissionIdentityIsPersistedOnUserMessageAndAllEvents(): void
    {
        $handler = $this->createStub(StreamFixture::class);
        $handler->method('events')->willReturnCallback(static function (): \Generator { yield new TextChunk('provider-id', 'Answer'); });
        $handler->method('getMessage')->willReturn(new AssistantMessage('Answer'));
        $events = [];
        [$job, , $pdo] = $this->job($handler, static function (array $event) use (&$events): void { $events[] = $event; });
        $payload = $this->payload('correlation');
        $payload['submissionId'] = 'submission-exact';
        $job->handle($payload);
        $display = json_decode($pdo->query('SELECT display_messages FROM chat_history')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('submission-exact', $display[0]['__meta']['claire_submission_id']);
        foreach ($events as $event) {
            self::assertSame('submission-exact', $event['payload']['submissionId']);
        }
        self::assertSame('succeeded', $events[count($events) - 1]['payload']['turnStatus']);
    }

    public function testLostSuccessCommitAcknowledgementNeverRollsBackOrReplays(): void
    {
        $handler = $this->createMock(StreamFixture::class);
        $handler->expects(self::once())->method('events')->willReturnCallback(static function (): \Generator { yield from []; });
        $handler->method('getMessage')->willReturn(new AssistantMessage('Answer'));
        $queue = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);
        $queue->expects(self::never())->method('dispatch');
        [$job, $publisher, $pdo, , $memory] = $this->job($handler,
            connectionClass: WebCommitReplyLostConnection::class, semanticEnabled: true, semanticQueue: $queue);
        $memory->setEnabled('user-1', true);
        $job->handle($this->payload('lost-commit'));
        self::assertSame('succeeded', $pdo->query('SELECT status FROM chat_turn')->fetchColumn());
        self::assertSame('done', $publisher->generationState()->get('user-1', 'lost-commit')['status']);
        self::assertSame('pending', $pdo->query('SELECT status FROM semantic_memory_excerpt')->fetchColumn());
        $job->handle($this->payload('lost-commit'));
    }

    public function testOldTerminalRetryCannotRepopulateEmptyRedisOrReplaceQueuedGeneration(): void
    {
        $handler = $this->createMock(StreamFixture::class);
        $handler->expects(self::never())->method('events');
        [$job, $publisher] = $this->job($handler);
        $sql = new \ReflectionProperty($job, 'connection')->getValue($job);
        $journal = new \App\Services\ChatTurnJournal($sql);
        $journal->begin('z-old', 'user-1', 'thread', 'web', 'z-old');
        $journal->succeed('z-old', 'user-1');
        $journal->begin('a-new', 'user-1', 'thread', 'web', 'a-new');
        $journal->succeed('a-new', 'user-1');
        $sql->executeStatement('UPDATE chat_turn SET created_at = 1234');
        $payload = $this->payload('thread');
        $payload['messageId'] = 'z-old';
        $job->handle($payload);
        self::assertSame([], $publisher->generationState()->get('user-1', 'thread'));
        $publisher->generationState()->set('user-1', 'thread', 'z-old', 'running', true);
        $payload['messageId'] = 'a-new';
        $job->handle($payload);
        self::assertSame('a-new', $publisher->generationState()->get('user-1', 'thread')['messageId']);
        $publisher->generationState()->set('user-1', 'thread', 'pending', 'queued', false);
        $job->handle($payload);
        self::assertSame('pending', $publisher->generationState()->get('user-1', 'thread')['messageId']);
        self::assertSame('queued', $publisher->generationState()->get('user-1', 'thread')['status']);
    }

    public function testWorkerOnlyFinalizesPreEntryFailureAfterConfirmedDeadLetter(): void
    {
        foreach ([false, true] as $dead) {
            $events = [];
            [$job, $publisher, $pdo] = $this->job($this->createStub(StreamFixture::class),
                static function (array $event) use (&$events): void { $events[] = $event; });
            $sql = new \ReflectionProperty($job, 'connection')->getValue($job);
            $settings = new \ReflectionProperty($job, 'settings')->getValue($job);
            $payload = $this->payload('final-failure');
            $payload['session']['brain_avatar'] = 'missing';
            $publisher->generationState()->set('user-1', 'final-failure', 'message-final-failure', 'queued', false);
            $recovery = new \App\Services\ChatTurnRecovery($sql, $publisher,
                new \App\Services\TelegramGeneration($settings, $sql, $publisher->generationState()),
                $this->createStub(\App\Services\TelegramService::class), $settings, new NullLogger());
            $queue = $this->createStub(\App\Services\Queue\QueueRedisConnection::class);
            $queue->method('evaluate')->willReturnCallback(static function (string $script, array $args) use ($payload, $dead): mixed {
                if (str_contains($script, "'SCAN'")) {
                    return ['0', []];
                }
                if (str_starts_with($script, 'return redis.call')) {
                    return $dead ? 'dead' : 'delayed';
                }
                if (($args[4] ?? '') === 'reserve') {
                    return ['id', 'job', 'job_class', NewMessageJob::class,
                        'payload', json_encode($payload, JSON_THROW_ON_ERROR), 'token', 'reservation', 'queue_name', 'test'];
                }
                return 1;
            });
            $backend = new \App\Services\Queue\RedisQueueBackend($queue, $settings, $sql);
            $container = $this->createStub(ContainerInterface::class);
            $container->method('has')->willReturnCallback(static fn (string $id): bool => $id === \App\Services\ChatTurnRecovery::class);
            $container->method('get')->willReturnCallback(static fn (string $id): object => $id === NewMessageJob::class ? $job : $recovery);
            $worker = new \App\Services\Queue\QueueWorker($backend, $container, new NullLogger());
            self::assertSame(1, $worker->run(new \App\Services\Queue\QueueWorkerOptions('test', 0, 1, 10), 'worker'));
            self::assertSame($dead ? 'error' : 'queued', $publisher->generationState()->get('user-1', 'final-failure')['status']);
            self::assertSame($dead ? 'rolled_back' : false, $pdo->query('SELECT status FROM chat_turn')->fetchColumn());
            self::assertCount($dead ? 1 : 0, $events);
        }
    }

    public function testRecoverablePreStreamFailureCanBeRetried(): void
    {
        $handler = $this->createStub(StreamFixture::class);
        [$job, $publisher] = $this->job($handler);
        $payload = $this->payload('thread');
        $payload['session']['brain_avatar'] = 'missing';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $job->handle($payload);
                self::fail('Initialization failure was swallowed');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('missing', $exception->getMessage());
            }
        }
        self::assertSame([], $publisher->generationState()->get('user-1', 'thread'));
    }

    public function testInvalidPayloadCannotPublishUsingPreviousJobContext(): void
    {
        [$job] = $this->job($this->createStub(StreamFixture::class));
        new \ReflectionProperty($job, 'sessionId')->setValue($job, 'previous-user');
        try {
            $job->handle([]);
            self::fail('Invalid payload accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame('', new \ReflectionProperty($job, 'sessionId')->getValue($job));
        }
    }

    public function testQueuedJobCannotRecreateDeletedThread(): void
    {
        $handler = $this->createMock(StreamFixture::class);
        $handler->expects(self::never())->method('events');
        [$job, $publisher] = $this->job($handler);
        $publisher->generationState()->set('user-1', 'deleted-thread', '', 'deleted', false);
        $job->handle($this->payload('deleted-thread'));
        self::assertSame('deleted', $publisher->generationState()->get('user-1', 'deleted-thread')['status']);
    }

    public function testSupersededJobDoesNotRunOrOverwriteCurrentGeneration(): void
    {
        $handler = $this->createMock(StreamFixture::class);
        $handler->expects(self::never())->method('events');
        [$job, $publisher] = $this->job($handler);
        $state = $publisher->generationState();
        $state->set('user-1', 'thread', 'message-newer', 'queued', false);
        $before = $state->get('user-1', 'thread');
        $job->handle($this->payload('thread'));
        self::assertSame($before, $state->get('user-1', 'thread'));
    }

    /** @return array<string, mixed> */
    private function payload(string $thread): array
    {
        return ['threadId' => $thread, 'sessionId' => 'tab', 'messageId' => 'message-' . $thread,
            'message' => 'Question', 'session' => [Auth::USERID => 'user-1', 'brain_avatar' => 'test']];
    }

    /** @return array{NewMessageJob, ChatStreamPublisher, \PDO} */
    private function job(
        StreamFixture|FakeAIProvider $handler,
        ?callable $onPublish = null,
        ?callable $onSummary = null,
        ?AudioServiceInterface $audio = null,
        string $connectionClass = Connection::class,
        array $tools = [],
        bool $stopEnabled = false,
        bool $semanticEnabled = false,
        bool $redisUnavailable = false,
        ?\App\Services\Queue\QueueDispatcherInterface $semanticQueue = null,
        ?callable $onWarning = null,
    ): array
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'queue' => ['defaultQueue' => 'served-default'],
            'llm' => ['brains' => ['test' => StreamingJobTestAgent::class],
                'stop' => ['enabled' => $stopEnabled], 'semanticMemory' => ['enabled' => $semanticEnabled],
                'openai' => ['contextWindow' => 50000],
                'yamlBrains' => ['path' => '/tmp/kilo/no-brains']]]);
        $redis = $this->createStub(RedisClient::class);
        $storage = [];
        $redis->method('hgetall')->willReturnCallback(static function (string $key) use (&$storage, $redisUnavailable): array {
            if ($redisUnavailable) {
                throw new \RuntimeException('Synthetic Redis outage');
            }
            return $storage[$key] ?? [];
        });
        $redis->method('hset')->willReturnCallback(static function (string $key, array $value) use (&$storage, $redisUnavailable): int {
            if ($redisUnavailable) {
                throw new \RuntimeException('Synthetic Redis outage');
            }
            $storage[$key] = array_map(strval(...), $value);
            return 1;
        });
        $redis->method('publish')->willReturnCallback(static function (string $key, string $message) use ($onPublish, $redisUnavailable): int {
            if ($redisUnavailable) {
                throw new \RuntimeException('Synthetic Redis outage');
            }
            if ($onPublish !== null) {
                $onPublish(json_decode($message, true, flags: JSON_THROW_ON_ERROR));
            }
            return 1;
        });
        $redis->method('expire')->willReturn(true);
        $publisher = new ChatStreamPublisher($redis, new ChatStreamSubscriber($settings), $settings);
        $connection = \Doctrine\DBAL\DriverManager::getConnection([
            'driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => $connectionClass,
        ]);
        require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
        \App\Test\Support\ChatTurnSqlSchema::create($connection);
        if ($semanticEnabled) {
            $connection->executeStatement('CREATE TABLE account (id VARCHAR(255) PRIMARY KEY)');
            $connection->insert('account', ['id' => 'user-1']);
        }
        foreach (array_filter([
            $stopEnabled ? \Migrations\Version20260930000100::class : null,
            $semanticEnabled ? \Migrations\Version20260930000200::class : null,
        ]) as $class) {
            $migration = new $class($connection, new NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement());
            }
        }
        $memory = new \App\Services\SemanticMemoryRegistry($connection, enabled: $semanticEnabled);
        $pdo = $connection->getNativeConnection();
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message, array $context) use ($onWarning): void {
            if ($onWarning !== null) {
                $onWarning($message, $context);
            }
        });
        $logger->method('error')->willReturnCallback(static function (string $message) use ($onSummary): void {
            if ($message === 'Chat summary failed after successful generation' && $onSummary !== null) {
                $onSummary();
            }
        });
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static fn (string $class) => match ($class) {
            \PDO::class => $pdo,
            Connection::class => $connection,
            Settings::class => $settings,
            \Psr\Log\LoggerInterface::class => $logger,
            'test.tools' => $tools,
            default => $handler,
        });
        $renderer = new ChatDataRenderer(
            new GeneratedFileProcessor($settings, $this->createStub(EntityManagerInterface::class)));
        return [new NewMessageJob($logger, $renderer,
            new BrainRegistry($settings, $container, new ThemeRegistry($settings)),
            $publisher, new ChatAudioPublisher($audio ?? $this->createStub(AudioServiceInterface::class),
                $publisher, new NullLogger()), $connection, $settings, $memory, $semanticQueue),
            $publisher, $pdo, $connection, $memory];
    }
}

final class WebCommitReplyLostConnection extends Connection
{
    private bool $lost = false;

    public function commit(): void
    {
        parent::commit();
        if (! $this->lost && $this->fetchOne("SELECT id FROM chat_turn WHERE status = 'succeeded'") !== false) {
            $this->lost = true;
            throw new \RuntimeException('Success commit acknowledgement lost');
        }
    }
}
