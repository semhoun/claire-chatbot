<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\HistoryController;
use App\Middleware\JwtSessionMiddleware;
use App\Renderer\ChatDataRenderer;
use App\Services\Auth;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;

#[AllowMockObjectsWithoutExpectations]
final class HistoryControllerTest extends TestCase
{
    public function testListReturnsOnlyHistoryMetadataScopedToTheSessionUser(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:']]);
        $history = $this->createStub(\App\Entity\ChatHistory::class);
        $history->method('getThreadId')->willReturn('thread-1');
        $history->method('getTitle')->willReturn(null);
        $history->method('getSummary')->willReturn(' <summary> ');
        $history->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('2026-09-12T12:00:00+00:00'));
        $repository = $this->createMock(\App\Repository\ChatHistoryRepository::class);
        $repository->expects(self::once())->method('getHistoryList')->with('user-1')->willReturn([$history]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $redis = $this->createStub(\App\Services\RedisClient::class);
        $publisher = new \App\Services\ChatStreamPublisher($redis,
            new \App\Services\ChatStreamSubscriber($settings), $settings);
        $controller = new HistoryController($this->chatRenderer($settings, $entityManager),
            $entityManager, $settings, $publisher,
            $this->createStub(\App\Services\Queue\QueueDispatcherInterface::class),
            $this->createStub(Filesystem::class));
        $request = new \Slim\Psr7\Factory\ServerRequestFactory()->createServerRequest('GET', '/history/list')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE,
                new \App\Services\Session\InMemorySession([Auth::USERID => 'user-1']));
        $response = $controller->list($request, new \Slim\Psr7\Response());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['histories' => [[
            'threadId' => 'thread-1', 'title' => 'Conversation', 'summary' => '<summary>',
            'updatedAt' => '2026-09-12T12:00:00+00:00',
        ]]], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCreateDelegatesGenerationStateToAtomicDispatcher(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'queue' => ['defaultQueue' => 'default']]);
        $session = new \App\Services\Session\InMemorySession([Auth::USERID => 'user-1', 'brain_avatar' => 'test']);
        $pdo = new \PDO('sqlite::memory:');
        $connection = $this->createStub(\Doctrine\DBAL\Connection::class);
        $connection->method('getNativeConnection')->willReturn($pdo);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $redis = $this->createMock(\App\Services\RedisClient::class);
        $redis->expects(self::never())->method('hset');
        $redis->expects(self::never())->method('publish');
        $publisher = new \App\Services\ChatStreamPublisher($redis,
            new \App\Services\ChatStreamSubscriber($settings), $settings);
        $queue = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);
        $captured = [];
        $queue->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (string $job, array $payload, string $queueName) use (&$captured, $pdo): string {
                $lock = new \App\Services\ChatThreadLock($pdo, 'user-1', $payload['threadId']);
                self::assertSame(\App\Job\Web\StartThreadJob::class, $job);
                self::assertSame('default', $queueName);
                self::assertSame('user-1', $payload['session'][Auth::USERID]);
                $captured = $payload;
                $lock->release();
                return 'queued-opening';
            },
        );
        $controller = new HistoryController($this->chatRenderer($settings, $entityManager),
            $entityManager, $settings, $publisher,
            $queue, $this->createStub(Filesystem::class));
        $request = new \Slim\Psr7\Factory\ServerRequestFactory()->createServerRequest('POST', '/history/new')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session);
        $response = $controller->create($request, new \Slim\Psr7\Response());
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($captured['threadId'], $payload['threadId']);
        self::assertSame($captured['sessionId'], $payload['sessionId']);
        $lock = new \App\Services\ChatThreadLock($pdo, 'user-1', $payload['threadId']);
        $lock->release();
    }

    public function testDeleteLastExchangeRejectsQueuedGenerationBeforeMutatingHistory(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:']]);
        $session = new \App\Services\Session\InMemorySession([Auth::USERID => 'queued-user']);
        $pdo = new \PDO('sqlite::memory:');
        $connection = $this->createStub(\Doctrine\DBAL\Connection::class);
        $connection->method('getNativeConnection')->willReturn($pdo);
        $connection->method('fetchOne')->willReturn(false);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::never())->method('getRepository');
        $redis = $this->createStub(\App\Services\RedisClient::class);
        $redis->method('hgetall')->willReturn(['status' => 'queued', 'messageId' => 'pending']);
        $publisher = new \App\Services\ChatStreamPublisher($redis,
            new \App\Services\ChatStreamSubscriber($settings), $settings);
        $controller = new HistoryController($this->chatRenderer($settings, $entityManager),
            $entityManager, $settings, $publisher,
            $this->createStub(\App\Services\Queue\QueueDispatcherInterface::class),
            $this->createStub(Filesystem::class));
        $request = new \Slim\Psr7\Factory\ServerRequestFactory()->createServerRequest('DELETE', '/history/last')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread']);
        $response = $controller->deleteLastExchange($request, new \Slim\Psr7\Response());
        self::assertSame(409, $response->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\TestWith([[]])]
    #[\PHPUnit\Framework\Attributes\TestWith([['status' => 'error', 'messageId' => 'pending']])]
    public function testDeleteLastExchangeRefusesUnrecoveredSqlTurn(array $redisState): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once __DIR__ . '/../../Support/ChatTurnSqlSchema.php';
        \App\Test\Support\ChatTurnSqlSchema::create($connection);
        new \App\Services\ChatTurnJournal($connection)->begin('pending', 'user-1', 'thread', 'web', 'pending');
        $before = $connection->fetchAllAssociative('SELECT * FROM chat_history');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::never())->method('getRepository');
        $redis = $this->createStub(\App\Services\RedisClient::class);
        $redis->method('hgetall')->willReturn($redisState);
        $settings = new Settings(['redis' => ['prefix' => 'test:']]);
        $publisher = new \App\Services\ChatStreamPublisher($redis,
            new \App\Services\ChatStreamSubscriber($settings), $settings);
        $controller = new HistoryController($this->chatRenderer($settings, $entityManager),
            $entityManager, $settings, $publisher,
            $this->createStub(\App\Services\Queue\QueueDispatcherInterface::class),
            $this->createStub(Filesystem::class));
        $request = new \Slim\Psr7\Factory\ServerRequestFactory()->createServerRequest('DELETE', '/history/last')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE,
                new \App\Services\Session\InMemorySession([Auth::USERID => 'user-1']))
            ->withParsedBody(['threadId' => 'thread']);

        self::assertSame(409, $controller->deleteLastExchange($request, new \Slim\Psr7\Response())->getStatusCode());
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM chat_history'));
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['sess-current'])]
    #[\PHPUnit\Framework\Attributes\TestWith([''])]
    public function testDeleteLastExchangeUsesRequestedThreadInsteadOfStaleSessionThread(string $sessionId): void
    {
        $settings = new Settings([
            'llm' => [
                'openai' => ['contextWindow' => 50000],
                'brains' => [],
                'yamlBrains' => ['path' => '/tmp/brains'],
            ],
            'redis' => ['prefix' => 'claire:'],
        ]);
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn (string $key): ?string => match ($key) {
            Auth::USERID => 'user-1',
            'threadId' => 'stale-thread',
            default => null,
        });
        $session->expects($this->once())->method('set')->with('threadId', 'current-thread');

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(
            "CREATE TABLE chat_history (user_id TEXT NOT NULL, thread_id TEXT PRIMARY KEY, messages TEXT NOT NULL, display_messages TEXT NOT NULL DEFAULT '[]', display_messages_count INTEGER NOT NULL DEFAULT 0, title TEXT DEFAULT NULL, summary TEXT DEFAULT NULL, revision INTEGER NOT NULL DEFAULT 0, current_turn_id TEXT)"
        );
        $chatHistory = new \App\Brain\ChatHistory\UserChatHistory(
            $session,
            $pdo,
            threadId: 'current-thread'
        );
        $chatHistory->replaceDisplayMessages([
            new \NeuronAI\Chat\Messages\UserMessage('Question'),
            new \NeuronAI\Chat\Messages\AssistantMessage('Réponse'),
        ]);
        $chatHistory->replaceMessages([
            new \NeuronAI\Chat\Messages\UserMessage('Question'),
            new \NeuronAI\Chat\Messages\AssistantMessage('Réponse'),
        ]);

        $repository = $this->getMockBuilder(\App\Repository\ChatHistoryRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentUserChatHistory'])
            ->getMock();
        $repository->expects($this->once())
            ->method('getCurrentUserChatHistory')
            ->with($session, 'current-thread')
            ->willReturn(new \App\Entity\ChatHistory());

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getNativeConnection')->willReturn($pdo);
        $connection->method('fetchOne')->willReturn(false);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturn($repository);

        $redis = $this->createMock(\App\Services\RedisClient::class);
        $redis->method('hgetall')->willReturn([]);
        $redis->method('expire')->willReturn(true);
        $redis->expects($sessionId === '' ? self::never() : self::once())
            ->method('publish')
            ->with(
                'claire:sse:chat:' . \App\Services\ChatStreamSubscriber::scope('user-1', 'sess-current'),
                $this->callback(static function (string $message): bool {
                    $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);

                    return is_array($event)
                        && $event['event'] === 'chat.snapshot'
                        && $event['payload']['restoredMessage'] === 'Question';
                })
            )
            ->willReturn(1);
        $subscriber = new \App\Services\ChatStreamSubscriber($settings);
        $publisher = new \App\Services\ChatStreamPublisher($redis, $subscriber, $settings);

        $controller = new HistoryController(
            $this->chatRenderer($settings, $entityManager),
            $entityManager,
            $settings,
            $publisher,
            $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class),
            $this->createMock(Filesystem::class)
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(JwtSessionMiddleware::SESSION_ATTRIBUTE)
            ->willReturn($session);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getQueryParams')->willReturn([
            'threadId' => 'current-thread',
            'message' => '',
            'sessionId' => $sessionId,
        ]);

        $result = $controller->deleteLastExchange(
            $request,
            (new ResponseFactory())->createResponse()
        );

        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame('current-thread', $payload['threadId']);
        $this->assertSame('Question', $payload['removedMessage']);
        $this->assertSame([], $payload['messages']);
        $this->assertArrayNotHasKey('html', $payload);
    }

    public function testOpenReturnsJsonChatIdForPersistentReconnect(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $settings = new Settings([
            'llm' => [
                'openai' => ['contextWindow' => 50000],
                'brains' => [],
                'yamlBrains' => ['path' => '/tmp/brains'],
            ],
            'redis' => [
                'prefix' => 'claire:',
            ],
        ]);
        $session = $this->createMock(SessionInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $pdo = new \PDO('sqlite::memory:');

        $pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT, display_messages TEXT, display_messages_count INTEGER, title TEXT NULL, summary TEXT NULL, revision INTEGER NOT NULL DEFAULT 0, current_turn_id TEXT)');
        $pdo->prepare('INSERT INTO chat_history (user_id, thread_id, messages, display_messages, display_messages_count) VALUES (?, ?, ?, ?, ?)')
            ->execute(['user-1', 'thread-1', '[]', '[{"role":"assistant","content":"Bonjour","metadata":{"timestamp":"2026-01-01T00:00:00+00:00"}}]', 1]);

        $session->method('get')->willReturnCallback(static function (string $key) {
            return match ($key) {
                Auth::USERID => 'user-1',
                'threadId' => 'thread-1',
                default => null,
            };
        });
        $session->expects($this->once())->method('set')->with('threadId', 'thread-1');

        $entityManager->method('getConnection')->willReturn($connection);
        $connection->method('getNativeConnection')->willReturn($pdo);
        $redis = $this->createMock(\App\Services\RedisClient::class);
        $redis->method('hgetall')->willReturn([]);
        $redis->method('expire')->willReturn(true);
        $redis->expects(self::never())->method('publish');
        $redis->expects(self::never())->method('lpush');
        $subscriber = new \App\Services\ChatStreamSubscriber($settings);
        $chatStreamPublisher = new \App\Services\ChatStreamPublisher($redis, $subscriber, $settings);
        $filesystem = $this->createMock(Filesystem::class);
        $queueDispatcher = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);

        $controller = new HistoryController(
            $this->chatRenderer($settings, $entityManager),
            $entityManager,
            $settings,
            $chatStreamPublisher,
            $queueDispatcher,
            $filesystem
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(fn (string $name) => match ($name) {
            JwtSessionMiddleware::SESSION_ATTRIBUTE => $session,
            'threadId' => 'thread-1',
            default => null,
        });

        $response = (new ResponseFactory())->createResponse();
        $result = $controller->open($request, $response);

        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertSame('{"threadId":"thread-1"}', (string) $result->getBody());
    }

    public function testOpenPublishesSnapshotToSessionIdWhenProvided(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $settings = new Settings([
            'llm' => [
                'openai' => ['contextWindow' => 50000],
                'brains' => [],
                'yamlBrains' => ['path' => '/tmp/brains'],
            ],
            'redis' => [
                'prefix' => 'claire:',
            ],
        ]);
        $session = $this->createMock(SessionInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $pdo = new \PDO('sqlite::memory:');

        $pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT, display_messages TEXT, display_messages_count INTEGER, title TEXT NULL, summary TEXT NULL, revision INTEGER NOT NULL DEFAULT 0, current_turn_id TEXT)');
        $pdo->prepare('INSERT INTO chat_history (user_id, thread_id, messages, display_messages, display_messages_count) VALUES (?, ?, ?, ?, ?)')
            ->execute(['user-1', 'thread-1', '[]', '[{"role":"assistant","content":"Bonjour","metadata":{"timestamp":"2026-01-01T00:00:00+00:00"}}]', 1]);

        $session->method('get')->willReturnCallback(static function (string $key) {
            return match ($key) {
                Auth::USERID => 'user-1',
                'threadId' => 'thread-1',
                default => null,
            };
        });

        $entityManager->method('getConnection')->willReturn($connection);
        $connection->method('getNativeConnection')->willReturn($pdo);
        $redis = $this->createMock(\App\Services\RedisClient::class);
        $redis->method('hgetall')->willReturn(['status' => 'running', 'messageId' => 'active-1']);
        $redis->method('expire')->willReturn(true);
        // Snapshot is published to the user's tab, not the conversation.
        $redis->expects($this->once())
            ->method('publish')
            ->with(
                'claire:sse:chat:' . \App\Services\ChatStreamSubscriber::scope('user-1', 'sess-abc123'),
                $this->callback(static function (string $payload): bool {
                    $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

                    return is_array($data)
                        && $data['event'] === 'chat.snapshot'
                        && $data['threadId'] === 'thread-1'
                        && $data['payload']['responding'] === true
                        && $data['payload']['activeMessageId'] === 'active-1'
                        && $data['payload']['threadId'] === 'thread-1'
                        && $data['payload']['sessionId'] === 'sess-abc123'
                        && $data['payload']['messages'][0]['message'] === 'Bonjour';
                })
            )
            ->willReturn(1);
        $subscriber = new \App\Services\ChatStreamSubscriber($settings);
        $chatStreamPublisher = new \App\Services\ChatStreamPublisher($redis, $subscriber, $settings);
        $filesystem = $this->createMock(Filesystem::class);
        $queueDispatcher = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);

        $controller = new HistoryController(
            $this->chatRenderer($settings, $entityManager),
            $entityManager,
            $settings,
            $chatStreamPublisher,
            $queueDispatcher,
            $filesystem
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(fn (string $name) => match ($name) {
            JwtSessionMiddleware::SESSION_ATTRIBUTE => $session,
            'threadId' => 'thread-1',
            default => null,
        });
        // Provide sessionId as query parameter (for GET request)
        $request->method('getQueryParams')->willReturn(['sessionId' => 'sess-abc123']);

        $response = (new ResponseFactory())->createResponse();
        $result = $controller->open($request, $response);

        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertSame('{"threadId":"thread-1"}', (string) $result->getBody());
    }

    private function chatRenderer(
        Settings $settings,
        EntityManagerInterface $entityManager,
    ): ChatDataRenderer {
        return new ChatDataRenderer(
            new GeneratedFileProcessor($settings, $entityManager)
        );
    }
}
