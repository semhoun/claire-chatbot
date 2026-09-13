<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\ChatHistory\UserChatHistory;
use App\Controller\SseInternalController;
use App\Entity\ChatHistory;
use App\Entity\User;
use App\Middleware\SseInternalMiddleware;
use App\Renderer\ChatDataRenderer;
use App\Services\Auth;
use App\Services\ChatGenerationState;
use App\Services\ChatSnapshot;
use App\Services\JwtTokenService;
use App\Services\RedisClient;
use App\Services\RememberSession;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use App\Services\SseAccess;
use App\Services\SseAuthorization;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SseAccessDoctrineTest extends TestCase
{
    public static function accessChanges(): iterable
    {
        yield 'user deleted while snapshot is rendered' => ['delete', 401];
        yield 'thread reassigned while snapshot is rendered' => ['reown', 403];
    }

    #[DataProvider('accessChanges')]
    public function testFinalSnapshotCheckBypassesManagedUserAndOwnership(string $change, int $status): void
    {
        $settings = new Settings([
            'base_url' => 'https://claire.test',
            'redis' => ['prefix' => 'test:'],
            'sse' => ['secret' => 'internal-test-secret', 'duration' => 1800],
            'session' => ['lifetime' => 120, 'jwt' => ['secret' => str_repeat('s', 32)]],
            'llm' => ['openai' => ['contextWindow' => 50000]],
        ]);
        $configuration = ORMSetup::createAttributeMetadataConfiguration([Settings::getAppRoot() . '/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $entityManager = new EntityManager($connection, $configuration);
        new SchemaTool($entityManager)->createSchema([
            $entityManager->getClassMetadata(User::class),
            $entityManager->getClassMetadata(ChatHistory::class),
        ]);
        // Production maps the relation without a database foreign key.
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach (['user-1', 'user-2'] as $id) {
            $user = new User();
            $user->setId($id);
            $entityManager->persist($user);
        }
        $history = new ChatHistory();
        $history->setUser($entityManager->find(User::class, 'user-1'));
        $history->setThreadId('thread-1');
        $history->setMessages('[]');
        $history->setDisplayMessages('[]');
        $entityManager->persist($history);
        $entityManager->flush();
        $entityManager->clear();

        // Populate the real identity map before admission, as rendering/repositories may do.
        $cachedUser = $entityManager->find(User::class, 'user-1');
        $cachedHistory = $entityManager->getRepository(ChatHistory::class)->findOneBy(['threadId' => 'thread-1']);
        self::assertSame($cachedUser, $cachedHistory->getUser());
        $session = new ArraySession();
        $session->start();
        $session->set(Auth::USERID, 'user-1');
        $session->set(Auth::AUTHENTICATED, true);
        new UserChatHistory($session, $connection->getNativeConnection(), threadId: 'thread-1')
            ->addMessage(new UserMessage('Private snapshot text'));

        $storage = [];
        $mutate = false;
        $reads = 0;
        $redis = $this->createStub(RedisClient::class);
        $redis->method('setex')->willReturnCallback(static function (string $key, int $ttl, string $value) use (&$storage): bool {
            $storage[$key] = $value;
            return true;
        });
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$storage): string|false {
            return $storage[$key] ?? false;
        });
        $redis->method('hgetall')->willReturnCallback(
            static function () use (&$mutate, &$reads, $change, $connection): array {
                if ($mutate && ++$reads === 2) {
                    // capture() has finished the SQL read and rendering; its final Redis read is next.
                    if ($change === 'delete') {
                        $connection->executeStatement('DELETE FROM account WHERE id = ?', ['user-1']);
                    } else {
                        $connection->executeStatement('UPDATE chat_history SET user_id = ? WHERE thread_id = ?',
                            ['user-2', 'thread-1']);
                    }
                }
                return ['messageId' => 'message-1', 'status' => 'done'];
            },
        );
        $tokens = new JwtTokenService($settings);
        $state = new ChatGenerationState($redis, $settings);
        $snapshots = new ChatSnapshot($state, $entityManager,
            new ChatDataRenderer(new GeneratedFileProcessor($settings, $entityManager)), $settings);
        $access = new SseAccess($tokens, new RememberSession($redis, $settings), $entityManager, $state, $settings);
        $authorizations = new SseAuthorization($access, $snapshots, $redis, $settings);
        $open = $authorizations->open([
            'credential' => $tokens->generateStreamToken($session, 'thread-1', 'tab-1'),
            'credentialType' => 'capability', 'method' => 'GET', 'path' => '/brain/stream',
            'threadId' => 'thread-1', 'sessionId' => 'tab-1',
        ]);
        self::assertSame('Private snapshot text', $authorizations->snapshot($open['authorization'])['messages'][0]['message']);

        $app = AppFactory::create();
        $app->post('/snapshot', new SseInternalController($authorizations));
        $app->addRoutingMiddleware();
        $app->add(new SseInternalMiddleware($settings));
        $request = new ServerRequestFactory()->createServerRequest('POST', 'http://127.0.0.1:8082/snapshot', [
            'SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => '8082', 'REMOTE_ADDR' => '127.0.0.1',
        ])->withHeader('Content-Type', 'application/json')->withHeader('X-Claire-Sse-Secret', 'internal-test-secret');
        $request->getBody()->write(json_encode(['authorization' => $open['authorization']], JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        $mutate = true;
        $response = $app->handle($request);

        self::assertSame(2, $reads);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        // These cached objects intentionally remain stale: security must not use or clear them.
        self::assertSame($cachedUser, $entityManager->find(User::class, 'user-1'));
        self::assertSame($cachedHistory,
            $entityManager->getRepository(ChatHistory::class)->findOneBy(['threadId' => 'thread-1']));
        self::assertSame('user-1', $cachedHistory->getUser()->getId());
        $entityManager->close();
        $connection->close();
    }
}
