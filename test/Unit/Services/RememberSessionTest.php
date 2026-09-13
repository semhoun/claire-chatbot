<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Controller\AuthController;
use App\Middleware\JwtSessionMiddleware;
use App\Renderer\VueShell;
use App\Services\Auth;
use App\Services\ChatGenerationState;
use App\Services\JwtTokenService;
use App\Services\OidcClient;
use App\Services\OidcTransaction;
use App\Services\RedisClient;
use App\Services\RememberSession;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class RememberSessionTest extends TestCase
{
    private array $records = [];
    private RememberSession $remember;
    private JwtTokenService $tokens;
    private JwtSessionMiddleware $middleware;
    private AuthController $controller;

    protected function setUp(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('setex')->willReturnCallback(function (string $key, int $ttl, string $value): bool {
            self::assertSame(RememberSession::TTL, $ttl);
            $this->records[$key] = $value;
            return true;
        });
        $redis->method('get')->willReturnCallback(fn (string $key): string|false => $this->records[$key] ?? false);
        $redis->method('del')->willReturnCallback(function (string $key): int {
            $exists = isset($this->records[$key]);
            unset($this->records[$key]);
            return (int) $exists;
        });
        $this->remember = new RememberSession($redis, new Settings(['redis' => ['prefix' => 'test:']]));
        $settings = new Settings(['session' => [
            'lifetime' => 900, 'refresh_before_expire' => 120, 'jwt' => ['secret' => str_repeat('s', 32)],
        ]]);
        $this->tokens = new JwtTokenService($settings);
        $this->middleware = new JwtSessionMiddleware($this->tokens, $settings, $this->remember);
        $auth = $this->createStub(Auth::class);
        $auth->method('restore')->willReturnCallback(static function (ArraySession $session, string $id): bool {
            $session->set(Auth::USERID, $id);
            $session->set(Auth::AUTHENTICATED, true);
            return true;
        });
        $auth->method('logout')->willReturnCallback(static fn (ArraySession $session) => $session->clear());
        $this->controller = new AuthController(
            new NullLogger(), (new ReflectionClass(OidcClient::class))->newInstanceWithoutConstructor(),
            $auth, $this->tokens, new VueShell(), new OidcTransaction($redis),
            $this->createStub(EntityManager::class), new ChatGenerationState($redis, $settings), $this->remember,
        );
    }

    private function session(): ArraySession
    {
        $session = new ArraySession();
        $session->start();
        $session->set(Auth::USERID, 'user-1');
        $session->set(Auth::AUTHENTICATED, true);
        return $session;
    }

    private function cookie(ArraySession $session): string
    {
        $cookie = $this->remember->issue($session);
        self::assertStringContainsString('; Path=/; Max-Age=604800; Secure; HttpOnly; SameSite=Lax', $cookie);
        self::assertStringNotContainsString('Domain=', $cookie);
        return explode(';', explode('=', $cookie, 2)[1], 2)[0];
    }

    private function request(string $path, ?string $cookie = null): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'https://claire.test' . $path)
            ->withHeader('Origin', 'https://claire.test')->withHeader('X-Claire-Remember', '1')
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withCookieParams($cookie === null ? [] : [RememberSession::COOKIE => $cookie]);
    }

    private function dispatch(ServerRequestInterface $request, string $action): \Psr\Http\Message\ResponseInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            fn ($request) => $this->controller->$action($request, new Response())
        );
        return $this->middleware->process($request, $handler);
    }

    public function testClosedBrowserRestorationAndLogoutRevokeAllDerivedTokens(): void
    {
        $session = $this->session();
        $cookie = $this->cookie($session);
        self::assertNotSame($cookie, $session->get(RememberSession::ID));
        self::assertStringNotContainsString($cookie, json_encode($this->records, JSON_THROW_ON_ERROR));
        $before = $this->records;
        $request = $this->request('/auth/remember', $cookie);
        $response = $this->dispatch($request, 'remember');
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame($before, $this->records);
        self::assertSame(204, $this->dispatch($request, 'remember')->getStatusCode());
        self::assertSame($before, $this->records);
        $jwt = $response->getHeaderLine('X-Claire-Token');
        $parsed = $this->tokens->parseSessionToken($jwt);
        self::assertSame('user-1', $parsed['sessionData'][Auth::USERID]);
        self::assertLessThanOrEqual(time() + 900, $parsed['expiresAt']->getTimestamp());
        $file = $this->tokens->generateFileToken($session, 'file-1');
        $stream = $this->tokens->generateStreamToken($session, 'thread-1', 'tab-1');
        $batch = $this->tokens->generateResourcesToken($session, [
            ['type' => 'file', 'fileId' => 'file-1'],
        ], time() + 600);
        $logout = $this->dispatch($this->request('/logout', $cookie), 'logout');
        self::assertSame(204, $logout->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        self::assertFalse($logout->hasHeader('X-Claire-Token'));
        self::assertSame([], $this->records);
        self::assertSame(401, $this->dispatch($request, 'remember')->getStatusCode());
        foreach ([$jwt, $file, $stream, $batch] as $token) {
            $path = $token === $jwt ? '/history/count'
                : ($token === $stream ? '/brain/stream' : '/files/serve/file-1');
            $api = (new ServerRequestFactory())->createServerRequest('GET', 'https://claire.test' . $path)
                ->withHeader('X-Claire-Auth', $token)
                ->withQueryParams($token === $stream ? ['threadId' => 'thread-1', 'sessionId' => 'tab-1'] : []);
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::never())->method('handle');
            self::assertSame(401, $this->middleware->process($api, $handler)->getStatusCode());
        }
    }

    public function testAbsoluteDeadlineIsNotExtendedByJwtOrCookieRenewal(): void
    {
        $session = $this->session();
        $cookie = $this->cookie($session);
        $key = array_key_first($this->records);
        $expires = time() + 30;
        $this->records[$key] = json_encode(['user_id' => 'user-1', 'expires' => $expires], JSON_THROW_ON_ERROR);
        $response = $this->dispatch($this->request('/auth/remember', $cookie), 'remember');
        $parsed = $this->tokens->parseSessionToken($response->getHeaderLine('X-Claire-Token'));
        self::assertSame($expires, $parsed['expiresAt']->getTimestamp());
        $session->setStorageFromArray($parsed['sessionData']);
        self::assertSame($expires, $this->tokens->parseSessionToken(
            $this->tokens->generateSessionToken($session)
        )['expiresAt']->getTimestamp());
        self::assertSame($expires, $this->tokens->parseFileToken(
            $this->tokens->generateFileToken($session, 'file-1')
        )['expiresAt']);
        $this->records[$key] = json_encode(['user_id' => 'user-1', 'expires' => time() - 1], JSON_THROW_ON_ERROR);
        self::assertSame(401, $this->dispatch($this->request('/auth/remember', $cookie), 'remember')->getStatusCode());
    }

    public function testCrossOriginAndSimpleRequestsCannotRenewOrLogout(): void
    {
        $cookie = $this->cookie($this->session());
        foreach (['remember', 'logout'] as $action) {
            $request = $this->request($action === 'remember' ? '/auth/remember' : '/logout', $cookie);
            foreach ([
                $request->withMethod('GET'), $request->withoutHeader('Origin'),
                $request->withHeader('Origin', 'null'), $request->withHeader('Origin', 'https://evil.test'),
                $request->withHeader('Origin', 'https://claire.test.evil.test'),
                $request->withoutHeader('X-Claire-Remember'), $request->withHeader('Sec-Fetch-Site', 'same-site'),
            ] as $forbidden) {
                $response = $this->dispatch($forbidden, $action);
                self::assertSame(403, $response->getStatusCode());
                self::assertFalse($response->hasHeader('Set-Cookie'));
                self::assertFalse($response->hasHeader('X-Claire-Token'));
                self::assertCount(1, $this->records);
            }
        }
    }

    public function testCookieDoesNotAuthenticateArbitraryRoutesOrEmbed(): void
    {
        $cookie = $this->cookie($this->session());
        foreach (['/history/count', '/embed', '/auth/refresh'] as $path) {
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->willReturnCallback(static function ($request): Response {
                self::assertNotSame(true, $request->getAttribute('session')->get(Auth::AUTHENTICATED));
                return new Response(401);
            });
            $response = $this->middleware->process($this->request($path, $cookie), $handler);
            self::assertSame(401, $response->getStatusCode());
        }
    }

    public function testMissingMalformedUnknownAndCorruptCookiesFailClosed(): void
    {
        foreach ([null, '', 'bad', str_repeat('a', 64)] as $cookie) {
            $response = $this->dispatch($this->request('/auth/remember', $cookie), 'remember');
            self::assertSame(401, $response->getStatusCode());
        }
        $cookie = $this->cookie($this->session());
        $this->records[array_key_first($this->records)] = '{broken';
        self::assertSame(401, $this->dispatch($this->request('/auth/remember', $cookie), 'remember')->getStatusCode());
    }

    public function testStorageFailureCannotIssueCookie(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('setex')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        (new RememberSession($redis, new Settings(['redis' => ['prefix' => 'test:']])))->issue($this->session());
    }

    public function testFailedRevocationIsNotReportedAsSuccessfulLogout(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('del')->willReturn(false);
        $remember = new RememberSession($redis, new Settings(['redis' => ['prefix' => 'test:']]));
        $this->expectException(\RuntimeException::class);
        $remember->revoke(str_repeat('a', 64));
    }

    public function testBoundSessionCannotChangeUserOrDeadline(): void
    {
        $session = $this->session();
        $this->cookie($session);
        $session->set(Auth::USERID, 'another-user');
        self::assertFalse($this->remember->valid($session));
        $session->set(Auth::USERID, 'user-1');
        $session->set(RememberSession::EXPIRES, time() + RememberSession::TTL + 1);
        self::assertFalse($this->remember->valid($session));
    }

    public function testIsolatedRedisAtomicExpiryAndRevocation(): void
    {
        if (getenv('OIDC_ISOLATED_REDIS_TEST') !== '1') {
            self::markTestSkipped('Explicit opt-in required for isolated Redis integration tests.');
        }
        $redis = new RedisClient();
        self::assertTrue($redis->connect('127.0.0.1', 16389, 2));
        $inspect = new \Redis();
        self::assertTrue($inspect->connect('127.0.0.1', 16389, 2));
        $remember = new RememberSession($redis, new Settings(['redis' => ['prefix' => 'test:']]));
        $session = $this->session();
        $cookie = explode(';', explode('=', $remember->issue($session), 2)[1], 2)[0];
        $key = 'test:auth:remember:' . $session->get(RememberSession::ID);
        try {
            self::assertGreaterThan(0, $inspect->ttl($key));
            self::assertLessThanOrEqual(RememberSession::TTL, $inspect->ttl($key));
            self::assertTrue($remember->valid($session));
            self::assertTrue($inspect->expire($key, 30));
            self::assertNotNull($remember->find($remember->cookieId($cookie)));
            self::assertLessThanOrEqual(30, $inspect->ttl($key));
            $remember->revoke($cookie);
            self::assertFalse($remember->valid($session));
            self::assertSame(0, $inspect->exists($key));
        } finally {
            $inspect->del($key);
            $inspect->close();
            $redis->close();
        }
    }
}
