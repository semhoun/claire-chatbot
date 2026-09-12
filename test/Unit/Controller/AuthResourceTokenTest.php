<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\AuthController;
use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Services\Auth;
use App\Services\ChatGenerationState;
use App\Services\JwtTokenService;
use App\Services\OidcClient;
use App\Services\OidcTransaction;
use App\Services\RedisClient;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class AuthResourceTokenTest extends TestCase
{
    public function testResourceTokenEndpointIsRegisteredAsPost(): void
    {
        $app = \Slim\Factory\AppFactory::create();
        $register = require Settings::getAppRoot() . '/config/routes/auth.php';
        $register($app);
        $routes = array_values(array_filter($app->getRouteCollector()->getRoutes(),
            static fn ($route): bool => $route->getPattern() === '/auth/resource-token'));
        self::assertCount(1, $routes);
        self::assertSame(['POST'], $routes[0]->getMethods());
        self::assertSame([AuthController::class, 'resourceToken'], $routes[0]->getCallable());
    }

    public static function resources(): iterable
    {
        yield 'owned file' => [['type' => 'file', 'fileId' => 'file-1'], 'user-1', true, false, 200];
        yield 'foreign file' => [['type' => 'file', 'fileId' => 'file-1'], 'other', true, false, 404];
        yield 'missing file' => [['type' => 'file', 'fileId' => 'file-1'], null, true, false, 404];
        yield 'owned thread' => [['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'], 'user-1', true, false, 200];
        yield 'unknown thread' => [['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'], null, true, false, 404];
        yield 'queued thread' => [['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'], null, true, false, 200, ['messageId' => 'opening-thread-1', 'status' => 'queued']];
        yield 'running thread' => [['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'], null, true, false, 200, ['messageId' => 'opening-thread-1', 'status' => 'running']];
        yield 'deleted thread' => [['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'], null, true, false, 404, ['messageId' => 'opening-thread-1', 'status' => 'deleted']];
        yield 'incomplete state' => [['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'], null, true, false, 404, ['status' => 'queued']];
        yield 'foreign thread' => [['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'], 'other', true, false, 404];
        yield 'anonymous' => [['type' => 'file', 'fileId' => 'file-1'], null, false, false, 401];
        yield 'capability cannot mint' => [['type' => 'file', 'fileId' => 'file-1'], null, true, true, 401];
        yield 'invalid type' => [['type' => 'session'], null, true, false, 400];
        yield 'invalid scope' => [['type' => 'file', 'fileId' => []], null, true, false, 400];
        yield 'malformed scope' => [['type' => 'stream', 'threadId' => '../other', 'sessionId' => 'tab-1'], null, true, false, 400];
        yield 'oversized scope' => [['type' => 'file', 'fileId' => str_repeat('x', 256)], null, true, false, 400];
    }

    #[DataProvider('resources')]
    public function testAuthenticatedOwnershipGuard(
        array $payload, ?string $owner, bool $authenticated, bool $resource, int $status, array $state = [],
    ): void {
        $entityManager = $this->createMock(EntityManager::class);
        $lookup = in_array($status, [200, 404], true);
        if ($lookup) {
            $entity = null;
            if ($owner !== null) {
                $user = new User();
                $user->setId($owner);
                $entity = $payload['type'] === 'file' ? new File() : new ChatHistory();
                $entity->setUser($user);
            }
            $repository = $this->createMock(EntityRepository::class);
            $repository->expects(self::once())->method('findOneBy')->with(
                $payload['type'] === 'file' ? ['fileId' => 'file-1'] : ['threadId' => 'thread-1']
            )->willReturn($entity);
            $entityManager->expects(self::once())->method('getRepository')->willReturn($repository);
        } else {
            $entityManager->expects(self::never())->method('getRepository');
        }
        $settings = new Settings(['redis' => ['prefix' => 'isolated-test:'], 'session' => [
            'jwt' => ['secret' => str_repeat('s', 32)], 'lifetime' => 3600,
        ]]);
        $tokens = new JwtTokenService($settings);
        $redis = $this->createMock(RedisClient::class);
        $generation = new ChatGenerationState($redis, $settings);
        if ($lookup && $payload['type'] === 'stream' && $owner === null) {
            $redis->expects(self::once())->method('hgetall')
                ->with($generation->key('user-1', 'thread-1'))->willReturn($state);
        } else {
            $redis->expects(self::never())->method('hgetall');
        }
        $controller = new AuthController(
            new NullLogger(), (new ReflectionClass(OidcClient::class))->newInstanceWithoutConstructor(),
            $this->createStub(Auth::class), $tokens, Twig::create(Settings::getAppRoot() . '/tmpl'),
            new OidcTransaction($this->createStub(RedisClient::class)), $entityManager, $generation,
        );
        $session = new ArraySession();
        $session->start();
        $session->set(Auth::USERID, 'user-1');
        $session->set(Auth::AUTHENTICATED, $authenticated);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/resource-token')
            ->withAttribute('session', $session)
            ->withAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT, time() + 600)
            ->withParsedBody($payload);
        if ($resource) {
            $request = $request->withAttribute(JwtSessionMiddleware::AUTH_RESOURCE, ['userId' => 'user-1']);
        }
        $response = $controller->resourceToken($request, new Response());
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        if ($status === 200) {
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $claims = $payload['type'] === 'file'
                ? $tokens->parseFileToken($body['token']) : $tokens->parseStreamToken($body['token']);
            self::assertSame('user-1', $claims['userId']);
            self::assertSame($claims['expiresAt'], $body['expiresAt']);
            self::assertNull($tokens->parseSessionToken($body['token']));
        }
    }
}
