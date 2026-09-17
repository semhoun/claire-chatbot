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
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

final class StreamingJobTestAgent extends Agent implements BrainAvatar
{
    public const string NAME = 'Test';
    public const string DESCRIPTION = 'Test';
    public const string AVATAR = '';
    public const string THEME = 'cyberpunk';

    private UserChatHistory $history;

    public function __construct(
        private ContainerInterface $testContainer,
        SessionInterface $session,
        ?string $threadId,
    ) {
        $this->history = new UserChatHistory($session, $testContainer->get(\PDO::class), threadId: $threadId);
    }

    public function stream(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler
    {
        foreach (is_array($messages) ? $messages : [$messages] as $message) {
            $this->history->addMessage($message);
        }
        return $this->testContainer->get(AgentHandler::class);
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        $this->history->addMessage($this->testContainer->get(AgentHandler::class)->getMessage());
        return $this->history;
    }
}

final class NewMessageJobTest extends TestCase
{
    public function testLockLostDuringToolPublicationStopsBeforeExecutingTheTool(): void
    {
        $toolCalls = 0;
        $handler = $this->createStub(AgentHandler::class);
        $handler->method('events')->willReturnCallback(static function () use (&$toolCalls): \Generator {
            yield new ToolCallChunk(new Tool('probe')->setCallId('call-probe'));
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
        $agent->method('stream')->willReturn($handler);
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
        $handler = $this->createStub(AgentHandler::class);
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
                    self::assertSame('message-completion-test', $display[1]['claire_message_id']);
                    self::assertSame('auto-message-completion-test',
                        $display[1][UserChatHistory::AUDIO_REQUEST_ID_METADATA]);
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
            $handler = $this->createMock(AgentHandler::class);
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
        $handler = $this->createStub(AgentHandler::class);
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
            $handler = $this->createStub(AgentHandler::class);
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
            $handler = $this->createStub(AgentHandler::class);
            $handler->method('events')->willReturnCallback(static function () use ($finalText): \Generator {
                foreach (['First. ', 'Second. '] as $index => $text) {
                    yield new TextChunk('provider-id', $text);
                    $tool = new Tool('search')->setCallId('call-' . $index);
                    yield new ToolCallChunk($tool);
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
        $handler = $this->createMock(AgentHandler::class);
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
        $handler = $this->createMock(AgentHandler::class);
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
        $handler = $this->createStub(AgentHandler::class);
        $handler->method('events')->willReturnCallback(static function (): \Generator { yield new TextChunk('id', 'Answer'); });
        $handler->method('getMessage')->willReturn(new AssistantMessage('Answer'));
        $events = [];
        [$job, , $pdo] = $this->job($handler, static function (array $event) use (&$events): void { $events[] = $event; });
        $payload = $this->payload('correlation');
        $payload['submissionId'] = 'submission-exact';
        $job->handle($payload);
        $display = json_decode($pdo->query('SELECT display_messages FROM chat_history')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('submission-exact', $display[0]['claire_submission_id']);
        foreach ($events as $event) {
            self::assertSame('submission-exact', $event['payload']['submissionId']);
        }
        self::assertSame('succeeded', $events[count($events) - 1]['payload']['turnStatus']);
    }

    public function testLostSuccessCommitAcknowledgementNeverRollsBackOrReplays(): void
    {
        $handler = $this->createMock(AgentHandler::class);
        $handler->expects(self::once())->method('events')->willReturnCallback(static function (): \Generator { yield from []; });
        $handler->method('getMessage')->willReturn(new AssistantMessage('Answer'));
        [$job, $publisher, $pdo] = $this->job($handler, connectionClass: WebCommitReplyLostConnection::class);
        $job->handle($this->payload('lost-commit'));
        self::assertSame('succeeded', $pdo->query('SELECT status FROM chat_turn')->fetchColumn());
        self::assertSame('done', $publisher->generationState()->get('user-1', 'lost-commit')['status']);
        $job->handle($this->payload('lost-commit'));
    }

    public function testOldTerminalRetryCannotRepopulateEmptyRedisOrReplaceQueuedGeneration(): void
    {
        $handler = $this->createMock(AgentHandler::class);
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
            [$job, $publisher, $pdo] = $this->job($this->createStub(AgentHandler::class),
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
        $handler = $this->createStub(AgentHandler::class);
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
        [$job] = $this->job($this->createStub(AgentHandler::class));
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
        $handler = $this->createMock(AgentHandler::class);
        $handler->expects(self::never())->method('events');
        [$job, $publisher] = $this->job($handler);
        $publisher->generationState()->set('user-1', 'deleted-thread', '', 'deleted', false);
        $job->handle($this->payload('deleted-thread'));
        self::assertSame('deleted', $publisher->generationState()->get('user-1', 'deleted-thread')['status']);
    }

    public function testSupersededJobDoesNotRunOrOverwriteCurrentGeneration(): void
    {
        $handler = $this->createMock(AgentHandler::class);
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
        AgentHandler $handler,
        ?callable $onPublish = null,
        ?callable $onSummary = null,
        ?AudioServiceInterface $audio = null,
        string $connectionClass = Connection::class,
    ): array
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'queue' => [],
            'llm' => ['brains' => ['test' => StreamingJobTestAgent::class],
                'yamlBrains' => ['path' => '/tmp/kilo/no-brains']]]);
        $redis = $this->createStub(RedisClient::class);
        $storage = [];
        $redis->method('hgetall')->willReturnCallback(static function (string $key) use (&$storage): array {
            return $storage[$key] ?? [];
        });
        $redis->method('hset')->willReturnCallback(static function (string $key, array $value) use (&$storage): int {
            $storage[$key] = array_map(strval(...), $value);
            return 1;
        });
        $redis->method('publish')->willReturnCallback(static function (string $key, string $message) use ($onPublish): int {
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
        $pdo = $connection->getNativeConnection();
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use ($onSummary): void {
            if ($message === 'Chat summary failed after successful generation' && $onSummary !== null) {
                $onSummary();
            }
        });
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static fn (string $class) =>
            $class === \PDO::class ? $pdo : $handler);
        $renderer = new ChatDataRenderer(
            new GeneratedFileProcessor($settings, $this->createStub(EntityManagerInterface::class)));
        return [new NewMessageJob($logger, $renderer,
            new BrainRegistry($settings, $container, new ThemeRegistry($settings)),
            $publisher, new ChatAudioPublisher($audio ?? $this->createStub(AudioServiceInterface::class),
                $publisher, new NullLogger()), $connection, $settings), $publisher, $pdo];
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
