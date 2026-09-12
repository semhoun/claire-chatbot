<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Middleware\JwtSessionMiddleware;
use App\Services\Auth;
use App\Services\JwtTokenService;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ResourceTokenSecurityTest extends TestCase
{
    private function settings(): Settings
    {
        return new Settings(['session' => [
            'jwt' => ['secret' => str_repeat('s', 32)], 'lifetime' => 3600, 'refresh_before_expire' => 4000,
        ]]);
    }

    private function session(): ArraySession
    {
        $session = new ArraySession();
        $session->start();
        $session->set(Auth::USERID, 'user-1');
        $session->set(Auth::AUTHENTICATED, true);
        return $session;
    }

    public function testAudiencesScopesAndBoundedExpiry(): void
    {
        $tokens = new JwtTokenService($this->settings());
        $session = $this->session();
        $file = $tokens->generateFileToken($session, 'file-1');
        $stream = $tokens->generateStreamToken($session, 'thread-1', 'tab-1');
        self::assertNull($tokens->parseSessionToken($file));
        self::assertNull($tokens->parseSessionToken($stream));
        self::assertNull($tokens->parseStreamToken($file));
        self::assertNull($tokens->parseFileToken($stream));
        self::assertNull($tokens->parseFileToken($tokens->generateMiniToken($session)));
        self::assertSame('user-1', $tokens->parseFileToken($file)['userId']);
        self::assertSame('file-1', $tokens->parseFileToken($file)['fileId']);
        self::assertSame('tab-1', $tokens->parseStreamToken($stream)['sessionId']);
        self::assertGreaterThan(time(), $tokens->parseFileToken($file)['expiresAt']);
        self::assertLessThanOrEqual(time() + 300, $tokens->parseFileToken($file)['expiresAt']);
        self::assertNull($tokens->parseFileToken($file . 'tampered'));
        self::assertNull($tokens->parseFileToken('malformed'));
    }

    public function testBatchExpiryIsBoundedBySessionAndCannotAuthenticateAsSession(): void
    {
        $tokens = new JwtTokenService($this->settings());
        $deadline = time() + 20;
        $token = $tokens->generateResourcesToken($this->session(), [
            ['type' => 'file', 'fileId' => 'file-1'],
            ['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'],
        ], $deadline);
        self::assertLessThanOrEqual($deadline, $tokens->parseResourcesToken($token)['expiresAt']);
        self::assertNull($tokens->parseSessionToken($token));
        self::assertNull($tokens->parseFileToken($token));
        self::assertNull($tokens->parseResourcesToken($token . 'tampered'));
        $this->expectException(\InvalidArgumentException::class);
        $tokens->generateResourcesToken($this->session(), [['type' => 'file', 'fileId' => 'file-1']], time() - 1);
    }

    public function testMalformedSignedBatchScopesAreRejected(): void
    {
        $tokens = new JwtTokenService($this->settings());
        $config = \Lcobucci\JWT\Configuration::forSymmetricSigner(
            new \Lcobucci\JWT\Signer\Hmac\Sha256(), \Lcobucci\JWT\Signer\Key\InMemory::plainText(str_repeat('s', 32))
        );
        $now = new \DateTimeImmutable();
        foreach ([null, [], 'all', [['type' => 'session']], [['type' => 'file', 'fileId' => '../x']],
            [['type' => 'stream', 'threadId' => 'thread-1']],
            array_fill(0, 33, ['type' => 'file', 'fileId' => 'a']),
            array_fill(0, 20, ['type' => 'file', 'fileId' => str_repeat('a', 255)]),
        ] as $resources) {
            $token = $config->builder()->relatedTo('user-1')->permittedFor('resources')
                ->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->modify('+60 seconds'))
                ->withClaim('resources', $resources)->getToken($config->signer(), $config->signingKey())->toString();
            self::assertNull($tokens->parseResourcesToken($token));
        }
    }

    public static function requests(): iterable
    {
        yield 'batch file' => ['batch', 'GET', '/files/serve/file-1', [], false, 200];
        yield 'batch second file' => ['batch', 'HEAD', '/files/serve/file-2', [], false, 200];
        yield 'batch wrong file' => ['batch', 'GET', '/files/serve/file-3', [], false, 403];
        yield 'batch stream' => ['batch', 'GET', '/brain/stream', ['threadId' => 'thread-1', 'sessionId' => 'tab-1'], false, 200];
        yield 'batch scope cross product' => ['batch', 'GET', '/brain/stream', ['threadId' => 'thread-1', 'sessionId' => 'tab-2'], false, 403];
        yield 'batch general' => ['batch', 'GET', '/history/list', [], true, 403];
        yield 'batch mint' => ['batch', 'POST', '/auth/resource-token', [], true, 403];
        yield 'batch write' => ['batch', 'POST', '/files/serve/file-1', [], false, 403];
        yield 'file get' => ['file', 'GET', '/files/serve/file-1', [], false, 200];
        yield 'generated file encoded path' => ['file', 'GET', '/files/serve/%40%40GENERATED%40%40artifact%40%40',
            [], false, 200, '', '@@GENERATED@@artifact@@'];
        yield 'generated file noncanonical path' => ['file', 'GET', '/files/serve/@@GENERATED@@artifact@@',
            [], false, 403, '', '@@GENERATED@@artifact@@'];
        yield 'file head' => ['file', 'HEAD', '/files/serve/file-1', [], false, 200];
        yield 'prefixed file' => ['file', 'GET', '/claire/files/serve/file-1', [], false, 200, '/claire'];
        yield 'wrong deployment prefix' => ['file', 'GET', '/other/files/serve/file-1', [], false, 403, '/claire'];
        yield 'unconfigured prefix' => ['file', 'GET', '/claire/files/serve/file-1', [], false, 403];
        yield 'wrong file' => ['file', 'GET', '/files/serve/file-2', [], false, 403];
        yield 'write' => ['file', 'POST', '/files/serve/file-1', [], false, 403];
        yield 'general' => ['file', 'GET', '/history/list', [], false, 403];
        yield 'mint' => ['file', 'POST', '/auth/resource-token', [], true, 403];
        yield 'exchange' => ['file', 'POST', '/auth/embed/exchange', [], false, 403];
        yield 'stream' => ['stream', 'GET', '/brain/stream', ['threadId' => 'thread-1', 'sessionId' => 'tab-1'], false, 200];
        yield 'prefixed stream' => ['stream', 'GET', '/claire/brain/stream', ['threadId' => 'thread-1', 'sessionId' => 'tab-1'], false, 200, '/claire'];
        yield 'wrong tab' => ['stream', 'GET', '/brain/stream', ['threadId' => 'thread-1', 'sessionId' => 'tab-2'], false, 403];
        yield 'wrong thread' => ['stream', 'GET', '/brain/stream', ['threadId' => 'thread-2', 'sessionId' => 'tab-1'], false, 403];
        yield 'missing scope' => ['stream', 'GET', '/brain/stream', [], false, 403];
        yield 'stream head' => ['stream', 'HEAD', '/brain/stream', ['threadId' => 'thread-1', 'sessionId' => 'tab-1'], false, 403];
        yield 'mini query' => ['mini', 'GET', '/files/serve/file-1', [], false, 401];
        yield 'mini header' => ['mini', 'GET', '/history/list', [], true, 401];
        yield 'session query' => ['session', 'GET', '/history/list', [], false, 401];
        yield 'session header' => ['session', 'GET', '/history/list', [], true, 200];
    }

    public function testExpiredFutureOverlongAndMultiAudienceCapabilitiesAreRejected(): void
    {
        $tokens = new JwtTokenService($this->settings());
        $config = \Lcobucci\JWT\Configuration::forSymmetricSigner(
            new \Lcobucci\JWT\Signer\Hmac\Sha256(), \Lcobucci\JWT\Signer\Key\InMemory::plainText(str_repeat('s', 32))
        );
        $now = new \DateTimeImmutable();
        foreach ([[-600, -1, false], [60, 120, false], [0, 301, false], [0, 60, true]] as [$iat, $exp, $multi]) {
            foreach (['resource-file', 'resources'] as $audience) {
                $builder = $config->builder()->relatedTo('user-1')->permittedFor($audience)
                    ->issuedAt($now->modify(sprintf('%+d seconds', $iat)))
                    ->canOnlyBeUsedAfter($now->modify(sprintf('%+d seconds', $iat)))
                    ->expiresAt($now->modify(sprintf('%+d seconds', $exp)))
                    ->withClaim('fileId', 'file-1')
                    ->withClaim('resources', [['type' => 'file', 'fileId' => 'file-1']]);
                if ($multi) {
                    $builder = $builder->permittedFor('session');
                }
                $token = $builder->getToken($config->signer(), $config->signingKey())->toString();
                self::assertNull($tokens->parseFileToken($token));
                self::assertNull($tokens->parseResourcesToken($token));
            }
        }
    }

    #[DataProvider('requests')]
    public function testMiddlewareConfinement(
        string $type, string $method, string $path, array $query, bool $header, int $status,
        string $basePath = '', string $fileId = 'file-1',
    ): void {
        $settings = $this->settings();
        $tokens = new JwtTokenService($settings);
        $session = $this->session();
        $token = match ($type) {
            'batch' => $tokens->generateResourcesToken($session, [
                ['type' => 'file', 'fileId' => 'file-1'],
                ['type' => 'file', 'fileId' => 'file-2'],
                ['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'],
                ['type' => 'stream', 'threadId' => 'thread-2', 'sessionId' => 'tab-2'],
            ], time() + 60),
            'file' => $tokens->generateFileToken($session, $fileId),
            'stream' => $tokens->generateStreamToken($session, 'thread-1', 'tab-1'),
            'mini' => $tokens->generateMiniToken($session),
            'session' => $tokens->generateSessionToken($session),
        };
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(\Slim\Routing\RouteContext::BASE_PATH, $basePath);
        if ($header) {
            $request = $request->withHeader('X-Claire-Auth', $token);
        } else {
            $query['token'] = $token;
        }
        $request = $request->withQueryParams($query);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($status === 200 ? self::once() : self::never())->method('handle')
            ->willReturnCallback(static function ($request): Response {
                self::assertGreaterThan(time(), $request->getAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT));
                $session = $request->getAttribute('session');
                self::assertSame('user-1', $session->get(Auth::USERID));
                $session->set('changed', true);
                return (new Response())->withHeader('X-Claire-Token', 'must-not-leak')
                    ->withHeader('X-Claire-Minitoken', 'must-not-leak');
            });
        $middleware = new JwtSessionMiddleware($tokens, $settings);
        $response = $middleware->process($request, $handler);
        self::assertSame($status, $response->getStatusCode());
        if ($type !== 'session' || $status !== 200) {
            self::assertFalse($response->hasHeader('X-Claire-Token'));
            self::assertFalse($response->hasHeader('X-Claire-Minitoken'));
        } else {
            self::assertNotNull($tokens->parseSessionToken($response->getHeaderLine('X-Claire-Token')));
        }
    }
}
