<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\BrainController;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Renderer\ChatDataRenderer;
use App\Repository\ChatHistoryRepository;
use App\Repository\UserRepository;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\ChatSnapshot;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\RedisClient;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BrainControllerTest extends TestCase
{
    public static function audioRequestIds(): array
    {
        return [
            'missing' => [null, 400],
            'empty' => ['', 400],
            'array' => [[], 400],
            'number' => [123, 400],
            'space' => [' request-1', 400],
            'newline' => ["request-1\n", 400],
            'slash' => ['request/1', 400],
            'too long' => [str_repeat('a', 129), 400],
            'uuid' => ['c989387b-b180-4bb2-9169-290c90cc3e93', 202],
            'allowed characters' => ['Az09._-', 202],
            'one character' => ['a', 202],
            'maximum length' => [str_repeat('a', 128), 202],
        ];
    }

    #[DataProvider('audioRequestIds')]
    public function testManualAudioValidatesAndDispatchesExactRequestId(mixed $id, int $status): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $audio = $this->createStub(AudioServiceInterface::class);
        $audio->method('isAvailable')->willReturn(true);
        [$controller, , $session] = $this->controller($queue, $audio);
        $session->set(AudioServiceInterface::ENABLED_SESSION_KEY, true);
        $queue->expects($status === 202 ? self::once() : self::never())->method('dispatch')
            ->with(\App\Job\Web\GenerateAudioJob::class, self::callback(
                static function (array $payload) use ($id, $session): bool {
                    self::assertSame([
                        'audioRequestId' => $id,
                        'threadId' => 'thread',
                        'sessionId' => 'tab',
                        'messageId' => 'message-1',
                        'text' => 'Bonjour',
                        'session' => $session->all(),
                    ], $payload);
                    return true;
                },
            ), 'default');
        $body = ['threadId' => 'thread', 'sessionId' => 'tab', 'messageId' => 'message-1', 'text' => 'Bonjour'];
        if ($id !== null) {
            $body['audioRequestId'] = $id;
        }
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/audio')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody($body);
        self::assertSame($status, $controller->generateAudio($request, new Response())->getStatusCode());
    }

    public function testSubmissionDelegatesQueuedStateToDispatchAndRejectsSecondGeneration(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        [$controller, $publisher, $session, $pdo] = $this->controller($queue);
        $queue->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (string $job, array $payload) use ($publisher, $pdo): string {
                $lock = new \App\Services\ChatThreadLock($pdo, 'user-1', 'thread');
                self::assertSame([], $publisher->generationState()->get('user-1', 'thread'));
                $publisher->generationState()->set('user-1', 'thread', $payload['messageId'], 'queued', false);
                self::assertSame(['responding' => true, 'activeMessageId' => $payload['messageId']],
                    $publisher->generationState()->snapshot('user-1', 'thread'));
                $lock->release();
                return 'queue-id';
            },
        );
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question']);
        self::assertSame(202, $controller->submitMessage($request, new Response())->getStatusCode());
        self::assertSame(409, $controller->submitMessage($request, new Response())->getStatusCode());
    }

    public function testDispatchFailureDoesNotCreateGenerationStateAndPropagates(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $failure = new \RuntimeException('Queue unavailable');
        $queue->expects(self::once())->method('dispatch')->willThrowException($failure);
        [$controller, $publisher, $session] = $this->controller($queue);
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question']);
        try {
            $controller->submitMessage($request, new Response());
            self::fail('Dispatch failure swallowed');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame(['responding' => false, 'activeMessageId' => null],
            $publisher->generationState()->snapshot('user-1', 'thread'));
        self::assertSame([], $publisher->generationState()->get('user-1', 'thread'));
    }

    public function testAmbiguousDispatchFailureDoesNotOverwriteAtomicallyQueuedGeneration(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        [$controller, $publisher, $session] = $this->controller($queue);
        $queue->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (string $job, array $payload) use ($publisher): never {
                $publisher->generationState()->set('user-1', 'thread', $payload['messageId'], 'queued', false);
                throw new \RuntimeException('Reply lost after Redis committed');
            },
        );
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question']);
        try {
            $controller->submitMessage($request, new Response());
            self::fail('Dispatch failure swallowed');
        } catch (\RuntimeException $exception) {
            self::assertSame('Reply lost after Redis committed', $exception->getMessage());
        }
        self::assertTrue($publisher->generationState()->snapshot('user-1', 'thread')['responding']);
        self::assertSame(409, $controller->submitMessage($request, new Response())->getStatusCode());
    }

    public function testAtomicDispatcherConflictReturns409WithoutChangingState(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queue->expects(self::once())->method('dispatch')
            ->willThrowException(new \App\Services\ChatGenerationBusyException('Another producer won'));
        [$controller, $publisher, $session] = $this->controller($queue);
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question']);
        self::assertSame(409, $controller->submitMessage($request, new Response())->getStatusCode());
        self::assertSame([], $publisher->generationState()->get('user-1', 'thread'));
    }

    public function testReconnectSnapshotUsesAuthenticatedUserAndDoesNotCreateMissingHistory(): void
    {
        [, $publisher, $session, $pdo, , $snapshots] = $this->controller($this->createStub(QueueDispatcherInterface::class));
        $publisher->generationState()->set('user-1', 'thread', 'active', 'queued', false);
        $snapshot = $snapshots->read($session, 'thread');
        self::assertTrue($snapshot['responding']);
        self::assertSame('active', $snapshot['activeMessageId']);
        $otherUser = new InMemorySession([Auth::USERID => 'other-user']);
        $otherSnapshot = $snapshots->read($otherUser, 'thread');
        self::assertFalse($otherSnapshot['responding']);
        self::assertNull($otherSnapshot['activeMessageId']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM chat_history')->fetchColumn());
    }

    public function testSnapshotRestoresPersistedAudioExpectationAfterReconnect(): void
    {
        [, $publisher, $session, $pdo, , $snapshots] = $this->controller(
            $this->createStub(QueueDispatcherInterface::class),
        );
        $history = new \App\Brain\ChatHistory\UserChatHistory($session, $pdo, threadId: 'thread');
        $history->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Question'));
        $history->addMessage(new \NeuronAI\Chat\Messages\AssistantMessage('Answer'));
        $history->identifyLastAssistantMessage('assistant-snapshot', 'auto-assistant-snapshot');
        $publisher->generationState()->set('user-1', 'thread', 'assistant-snapshot', 'done', true);
        $snapshot = $snapshots->read($session, 'thread');
        self::assertSame(['assistant-snapshot' => 'auto-assistant-snapshot'], $snapshot['audioRequestIds']);
        self::assertFalse($snapshot['responding']);
        self::assertSame(['messageId' => 'assistant-snapshot', 'status' => 'done'], $snapshot['generation']);
        self::assertSame('assistant-snapshot', $snapshot['generationMessageId']);
        self::assertSame('assistant-snapshot', $snapshot['messages'][1]['id']);
        self::assertSame('Answer', $snapshot['messages'][1]['message']);
        self::assertArrayNotHasKey('html', $snapshot);
    }

    public function testSnapshotRestoresInterruptedToolsAndSafeErrorAfterReconnect(): void
    {
        [, $publisher, $session, $pdo, , $snapshots] = $this->controller(
            $this->createStub(QueueDispatcherInterface::class),
        );
        $history = new \App\Brain\ChatHistory\UserChatHistory($session, $pdo, threadId: 'thread');
        $history->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Generate PDF'));
        $tool = new \NeuronAI\Tools\Tool('generate_pdf', 'Test');
        $tool->setCallId('pdf');
        $history->addMessage(new \NeuronAI\Chat\Messages\ToolCallMessage(null, [$tool]));
        foreach (['running', 'error', 'error'] as $status) {
            $publisher->generationState()->set('user-1', 'thread', 'attempt', $status, true);
            $snapshot = $snapshots->read($session, 'thread');
            self::assertSame($status, $snapshot['generationStatus']);
            self::assertSame($status === 'running', $snapshot['responding']);
            $renderedTool = $snapshot['messages'][1]['toolsCall'][0];
            self::assertSame($status === 'running', $renderedTool['running']);
            self::assertSame($status !== 'running', $renderedTool['interrupted'] ?? false);
            self::assertNull($renderedTool['result']);
        }
    }

    /** @return array{BrainController, ChatStreamPublisher, InMemorySession, \PDO, RedisClient, ChatSnapshot} */
    private function controller(QueueDispatcherInterface $queue, ?AudioServiceInterface $audio = null): array
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'llm' => ['openai' => ['contextWindow' => 50000]],
            'queue' => ['defaultQueue' => 'default'],
            'security' => ['cors' => ['allowed_origins' => []]]]);
        $session = new InMemorySession([Auth::USERID => 'user-1']);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT, '
            . 'display_messages TEXT, display_messages_count INTEGER DEFAULT 0, title TEXT, summary TEXT)');
        $connection = $this->createStub(Connection::class);
        $connection->method('getNativeConnection')->willReturn($pdo);
        $user = new User();
        $user->setId('user-1');
        $users = $this->createStub(UserRepository::class);
        $users->method('getCurrentUser')->willReturn($user);
        $histories = $this->createStub(ChatHistoryRepository::class);
        $histories->method('findOneBy')->willReturn(null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class) =>
            $class === User::class ? $users : $histories);
        $redis = $this->createStub(RedisClient::class);
        $storage = [];
        $redis->method('hgetall')->willReturnCallback(static function (string $key) use (&$storage): array {
            return $storage[$key] ?? [];
        });
        $redis->method('hset')->willReturnCallback(static function (string $key, array $value) use (&$storage): int {
            $storage[$key] = array_map(strval(...), $value);
            return 1;
        });
        $subscriber = new ChatStreamSubscriber($settings);
        $publisher = new ChatStreamPublisher($redis, $subscriber, $settings);
        $renderer = new ChatDataRenderer(new GeneratedFileProcessor($settings, $entityManager));
        return [new BrainController(new NullLogger(),
            $entityManager,
            $this->createStub(Filesystem::class), $settings, $audio ?? $this->createStub(AudioServiceInterface::class),
            $queue, $publisher),
            $publisher, $session, $pdo, $redis,
            new ChatSnapshot($publisher->generationState(), $entityManager, $renderer, $settings)];
    }
}
