<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\HistoryController;
use App\Middleware\JwtSessionMiddleware;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

#[AllowMockObjectsWithoutExpectations]
final class HistoryControllerTest extends TestCase
{
    public function testDeleteLastExchangeUsesRequestedThreadInsteadOfStaleSessionThread(): void
    {
        $settings = new Settings([
            'llm' => [
                'openai' => ['contextWindow' => 50000],
                'brains' => [],
                'yamlBrains' => ['path' => '/tmp/brains'],
            ],
            'redis' => ['prefix' => 'claire:'],
            'sse' => ['queue_ttl' => 3600],
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
            "CREATE TABLE chat_history (user_id TEXT NOT NULL, thread_id TEXT PRIMARY KEY, messages TEXT NOT NULL, display_messages TEXT NOT NULL DEFAULT '[]', display_messages_count INTEGER NOT NULL DEFAULT 0, title TEXT DEFAULT NULL, summary TEXT DEFAULT NULL)"
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
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturn($repository);

        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('fetch')
            ->with('partials/messages_list.twig', ['messages' => []])
            ->willReturn('<div></div>');

        $redis = $this->createMock(\App\Services\RedisClient::class);
        $redis->expects($this->once())
            ->method('rpush')
            ->with(
                'claire:sse:chat:sess-current:queue',
                $this->callback(static function (array $messages): bool {
                    $event = json_decode($messages[0], true);

                    return is_array($event)
                        && $event['event'] === 'chat.snapshot'
                        && $event['payload']['restoredMessage'] === 'Question';
                })
            )
            ->willReturn(1);
        $subscriber = new \App\Services\ChatStreamSubscriber($redis, $settings);
        $publisher = new \App\Services\ChatStreamPublisher($redis, $subscriber, $settings);

        $controller = new HistoryController(
            $this->createMock(LoggerInterface::class),
            $twig,
            $entityManager,
            new \App\Brain\BrainRegistry($settings, $this->createMock(ContainerInterface::class)),
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
            'sessionId' => 'sess-current',
        ]);

        $result = $controller->deleteLastExchange(
            $request,
            (new ResponseFactory())->createResponse()
        );

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame(
            '{"threadId":"current-thread","removedMessage":"Question","html":"<div><\/div>"}',
            (string) $result->getBody()
        );
    }

    public function testOpenReturnsJsonChatIdForPersistentReconnect(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $twig = $this->createMock(Twig::class);
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
            'sse' => [
                'queue_ttl' => 3600,
            ],
        ]);
        $session = $this->createMock(SessionInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $container = $this->createMock(ContainerInterface::class);
        $pdo = new \PDO('sqlite::memory:');

        $pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT, display_messages TEXT, display_messages_count INTEGER, title TEXT NULL, summary TEXT NULL)');
        $pdo->prepare('INSERT INTO chat_history (user_id, thread_id, messages, display_messages, display_messages_count) VALUES (?, ?, ?, ?, ?)')
            ->execute(['user-1', 'thread-1', '[]', '[{"role":"assistant","content":"Bonjour","metadata":{"timestamp":"2026-01-01T00:00:00+00:00"}}]', 1]);

        $brainRegistry = new \App\Brain\BrainRegistry($settings, $container);

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
        $twig->expects($this->once())
            ->method('fetch')
            ->with('partials/messages_list.twig', $this->isArray())
            ->willReturn('<div>snapshot</div>');
        $redis = $this->createMock(\App\Services\RedisClient::class);
        $redis->expects($this->once())
            ->method('rpush')
            ->with(
                'claire:sse:chat:thread-1:queue',
                $this->callback(static function (array $payloadArr): bool {
                    $payload = $payloadArr[0];
                    $data = json_decode($payload, true);

                    return is_array($data)
                        && $data['event'] === 'chat.snapshot'
                        && $data['threadId'] === 'thread-1'
                        && $data['payload']['html'] === '<div>snapshot</div>';
                })
            )
            ->willReturn(1);
        $subscriber = new \App\Services\ChatStreamSubscriber($redis, $settings);
        $chatStreamPublisher = new \App\Services\ChatStreamPublisher($redis, $subscriber, $settings);
        $filesystem = $this->createMock(Filesystem::class);
        $queueDispatcher = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);

        $controller = new HistoryController($logger, $twig, $entityManager, $brainRegistry, $settings, $chatStreamPublisher, $queueDispatcher, $filesystem);

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
        $logger = $this->createMock(LoggerInterface::class);
        $twig = $this->createMock(Twig::class);
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
            'sse' => [
                'queue_ttl' => 3600,
            ],
        ]);
        $session = $this->createMock(SessionInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $container = $this->createMock(ContainerInterface::class);
        $pdo = new \PDO('sqlite::memory:');

        $pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT, display_messages TEXT, display_messages_count INTEGER, title TEXT NULL, summary TEXT NULL)');
        $pdo->prepare('INSERT INTO chat_history (user_id, thread_id, messages, display_messages, display_messages_count) VALUES (?, ?, ?, ?, ?)')
            ->execute(['user-1', 'thread-1', '[]', '[{"role":"assistant","content":"Bonjour","metadata":{"timestamp":"2026-01-01T00:00:00+00:00"}}]', 1]);

        $brainRegistry = new \App\Brain\BrainRegistry($settings, $container);

        $session->method('get')->willReturnCallback(static function (string $key) {
            return match ($key) {
                Auth::USERID => 'user-1',
                'threadId' => 'thread-1',
                default => null,
            };
        });

        $entityManager->method('getConnection')->willReturn($connection);
        $connection->method('getNativeConnection')->willReturn($pdo);
        $twig->expects($this->once())
            ->method('fetch')
            ->with('partials/messages_list.twig', $this->isArray())
            ->willReturn('<div>snapshot</div>');
        $redis = $this->createMock(\App\Services\RedisClient::class);
        // Snapshot should be pushed to sessionId queue, not threadId
        $redis->expects($this->once())
            ->method('rpush')
            ->with(
                'claire:sse:chat:sess-abc123:queue',
                $this->callback(static function (array $payloadArr): bool {
                    $payload = $payloadArr[0];
                    $data = json_decode($payload, true);

                    return is_array($data)
                        && $data['event'] === 'chat.snapshot'
                        && $data['threadId'] === 'sess-abc123'
                        && $data['payload']['threadId'] === 'thread-1'
                        && $data['payload']['sessionId'] === 'sess-abc123'
                        && $data['payload']['html'] === '<div>snapshot</div>';
                })
            )
            ->willReturn(1);
        $subscriber = new \App\Services\ChatStreamSubscriber($redis, $settings);
        $chatStreamPublisher = new \App\Services\ChatStreamPublisher($redis, $subscriber, $settings);
        $filesystem = $this->createMock(Filesystem::class);
        $queueDispatcher = $this->createMock(\App\Services\Queue\QueueDispatcherInterface::class);

        $controller = new HistoryController($logger, $twig, $entityManager, $brainRegistry, $settings, $chatStreamPublisher, $queueDispatcher, $filesystem);

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
}
