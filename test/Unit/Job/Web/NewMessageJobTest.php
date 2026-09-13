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
use NeuronAI\Chat\History\ChatHistoryInterface;
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
        [$job, $publisher] = $this->job($handler);
        try {
            $job->handle($this->payload('thread'));
            self::fail('Failure was swallowed');
        } catch (\RuntimeException $exception) {
            self::assertInstanceOf(\App\Services\Queue\NonRetryableJobException::class, $exception);
            self::assertSame($failure, $exception->getPrevious());
        }
        self::assertSame('error', $publisher->generationState()->get('user-1', 'thread')['status']);
        $this->expectExceptionMessage('Unsafe chat retry refused');
        $job->handle($this->payload('thread'));
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
        self::assertSame('0', $publisher->generationState()->get('user-1', 'thread')['attempted']);
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
    ): array
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'sse' => ['queue_ttl' => 60],
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
        $redis->method('lpush')->willReturnCallback(static function (string $key, array $messages) use ($onPublish): int {
            if ($onPublish !== null) {
                $onPublish(json_decode($messages[0], true, flags: JSON_THROW_ON_ERROR));
            }
            return 1;
        });
        $redis->method('expire')->willReturn(true);
        $publisher = new ChatStreamPublisher($redis, new ChatStreamSubscriber($redis, $settings), $settings);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT,'
            . " display_messages TEXT, display_messages_count INTEGER, title TEXT, summary TEXT)");
        $connection = $this->createStub(Connection::class);
        $connection->method('getNativeConnection')->willReturn($pdo);
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
