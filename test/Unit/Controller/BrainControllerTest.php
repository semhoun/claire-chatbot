<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Brain\BrainRegistry;
use App\Controller\BrainController;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Renderer\ChatHtmlRenderer;
use App\Repository\ChatHistoryRepository;
use App\Repository\UserRepository;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\CorsHeaders;
use App\Services\Markdown;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\RedisClient;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use App\Services\SseEventFormatter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BufferedBrainStreamBody extends \Slim\Psr7\Stream
{
    public static self $instance;

    public function __construct()
    {
        parent::__construct(fopen('php://temp', 'r+'));
        self::$instance = $this;
    }
}

final class BrainControllerTest extends TestCase
{
    public static function idleTransitions(): array
    {
        return [
            'worker started' => ['queued', 'running', 'message-1', 1],
            'other tab completed' => ['running', 'done', 'message-1', 2],
            'other tab submitted' => ['done', 'queued', 'message-2', 2],
            'fast generation completed elsewhere' => ['done', 'done', 'message-2', 2],
        ];
    }

    #[DataProvider('idleTransitions')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIdleStreamReconcilesWithoutReplacingActiveGeneration(
        string $initialStatus,
        string $nextStatus,
        string $nextMessageId,
        int $expectedSnapshots,
    ): void {
        class_alias(BufferedBrainStreamBody::class, \Slim\Psr7\NonBufferedBody::class);
        [$controller, $publisher, $session, , $redis] = $this->controller(
            $this->createStub(QueueDispatcherInterface::class),
        );
        $publisher->generationState()->set('user-1', 'thread', 'message-1', $initialStatus, false);
        $calls = 0;
        $redis->method('brpop')->willReturnCallback(
            static function () use (&$calls, $publisher, $nextStatus, $nextMessageId): ?array {
                if (++$calls > 1) {
                    throw new \RuntimeException('End isolated stream');
                }
                $publisher->generationState()->set('user-1', 'thread', $nextMessageId, $nextStatus, true);
                return null;
            },
        );
        $request = new ServerRequestFactory()->createServerRequest('GET', '/brain/stream')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT, time() + 60)
            ->withQueryParams(['threadId' => 'thread', 'sessionId' => 'tab']);
        try {
            $controller->stream($request, new Response());
            self::fail('Test stream did not terminate');
        } catch (\RuntimeException $exception) {
            self::assertSame('End isolated stream', $exception->getMessage());
        }
        $output = (string) BufferedBrainStreamBody::$instance;
        self::assertSame($expectedSnapshots, substr_count($output, 'event: chat.snapshot'));
        $responding = in_array($nextStatus, ['queued', 'running'], true);
        self::assertStringContainsString('"responding":' . ($responding ? 'true' : 'false'), $output);
    }

    public function testStreamRequiresUnexpiredAuthentication(): void
    {
        [$controller, , $session, , $redis] = $this->controller($this->createStub(QueueDispatcherInterface::class));
        $redis->method('brpop')->willReturnCallback(static function (): never {
            self::fail('Expired authentication must not read Redis events');
        });
        foreach ([null, time() - 1] as $expiry) {
            $request = new ServerRequestFactory()->createServerRequest('GET', '/brain/stream')
                ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
                ->withAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT, $expiry)
                ->withQueryParams(['threadId' => 'thread', 'sessionId' => 'tab']);
            $response = $controller->stream($request, new Response());
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStreamDoesNotPublishAnEventReturnedAfterAuthenticationExpires(): void
    {
        class_alias(BufferedBrainStreamBody::class, \Slim\Psr7\NonBufferedBody::class);
        [$controller, , $session, , $redis] = $this->controller($this->createStub(QueueDispatcherInterface::class));
        $expiresAt = time() + 2;
        $calls = 0;
        $redis->method('brpop')->willReturnCallback(
            static function (array $keys, int|float $timeout) use ($expiresAt, &$calls): array {
                self::assertSame(1, ++$calls);
                self::assertGreaterThan(0, $timeout);
                self::assertLessThanOrEqual(1, $timeout);
                usleep((int) (max(0, $expiresAt - microtime(true) + 0.01) * 1_000_000));
                return [$keys[0], json_encode(['event' => 'chat.audio.ready', 'payload' => [
                    'sessionId' => 'tab', 'threadId' => 'thread', 'messageId' => 'late', 'audioData' => 'expired-data',
                ]], JSON_THROW_ON_ERROR)];
            },
        );
        $request = new ServerRequestFactory()->createServerRequest('GET', '/brain/stream')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT, $expiresAt)
            ->withQueryParams(['threadId' => 'thread', 'sessionId' => 'tab']);
        $response = $controller->stream($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $calls);
        self::assertStringNotContainsString('expired-data', (string) $response->getBody());
        self::assertStringNotContainsString('chat.audio.ready', (string) $response->getBody());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStreamDoesNotForwardAnotherThreadOnTheSameTab(): void
    {
        class_alias(BufferedBrainStreamBody::class, \Slim\Psr7\NonBufferedBody::class);
        [$controller, , $session, , $redis] = $this->controller($this->createStub(QueueDispatcherInterface::class));
        $calls = 0;
        $redis->method('brpop')->willReturnCallback(static function (array $keys) use (&$calls): array {
            if (++$calls > 1) {
                throw new \RuntimeException('End isolated stream');
            }
            return [$keys[0], json_encode(['event' => 'chat.audio.ready', 'payload' => [
                'sessionId' => 'tab', 'threadId' => 'other-thread', 'messageId' => 'other', 'audioData' => 'other-data',
            ]], JSON_THROW_ON_ERROR)];
        });
        $request = new ServerRequestFactory()->createServerRequest('GET', '/brain/stream')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT, time() + 60)
            ->withQueryParams(['threadId' => 'thread', 'sessionId' => 'tab']);
        try {
            $controller->stream($request, new Response());
            self::fail('Test stream did not terminate');
        } catch (\RuntimeException $exception) {
            self::assertSame('End isolated stream', $exception->getMessage());
        }
        self::assertStringNotContainsString('other-data', (string) BufferedBrainStreamBody::$instance);
        self::assertSame(1, substr_count((string) BufferedBrainStreamBody::$instance, 'event: chat.snapshot'));
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
        [$controller, $publisher, $session, $pdo] = $this->controller($this->createStub(QueueDispatcherInterface::class));
        $publisher->generationState()->set('user-1', 'thread', 'active', 'queued', false);
        $snapshot = new \ReflectionMethod($controller, 'readSnapshot')->invoke($controller, $session, 'thread');
        self::assertTrue($snapshot['responding']);
        self::assertSame('active', $snapshot['activeMessageId']);
        $otherUser = new InMemorySession([Auth::USERID => 'other-user']);
        $otherSnapshot = new \ReflectionMethod($controller, 'readSnapshot')->invoke($controller, $otherUser, 'thread');
        self::assertFalse($otherSnapshot['responding']);
        self::assertNull($otherSnapshot['activeMessageId']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM chat_history')->fetchColumn());
    }

    /** @return array{BrainController, ChatStreamPublisher, InMemorySession, \PDO, RedisClient} */
    private function controller(QueueDispatcherInterface $queue): array
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'llm' => ['openai' => ['contextWindow' => 50000]],
            'queue' => ['defaultQueue' => 'default'], 'sse' => ['pop_timeout' => 1],
            'security' => ['cors' => ['allowed_origins' => []]]]);
        $session = new InMemorySession([Auth::USERID => 'user-1']);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT, display_messages TEXT, title TEXT, summary TEXT)");
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
        $subscriber = new ChatStreamSubscriber($redis, $settings);
        $publisher = new ChatStreamPublisher($redis, $subscriber, $settings);
        $renderer = new ChatHtmlRenderer(new Markdown(), new GeneratedFileProcessor($settings, $entityManager));
        return [new BrainController(new NullLogger(), $renderer,
            new BrainRegistry($settings, $this->createStub(ContainerInterface::class)), $entityManager,
            $this->createStub(Filesystem::class), $settings, $this->createStub(AudioServiceInterface::class),
            $queue, $publisher, $subscriber, new SseEventFormatter(), new CorsHeaders($settings)),
            $publisher, $session, $pdo, $redis];
    }
}
