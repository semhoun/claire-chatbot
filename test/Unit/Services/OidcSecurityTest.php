<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Controller\AuthController;
use App\Services\Auth;
use App\Services\JwtTokenService;
use App\Services\OidcClient;
use App\Services\OidcTransaction;
use App\Services\RedisClient;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use App\Renderer\VueShell;

final class OidcSecurityTest extends TestCase
{
    private ?\Redis $isolatedRedis = null;

    /** @var list<string> */
    private array $transactionKeys = [];

    /** @var list<RedisClient> */
    private array $connections = [];

    protected function tearDown(): void
    {
        if ($this->isolatedRedis !== null) {
            foreach ($this->transactionKeys as $key) {
                $this->isolatedRedis->del($key);
            }
            $this->isolatedRedis->close();
        }
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        parent::tearDown();
    }

    private function connectIsolatedRedis(?RedisClient $client = null): RedisClient
    {
        if (getenv('OIDC_ISOLATED_REDIS_TEST') !== '1') {
            self::markTestSkipped('Explicit opt-in required for isolated Redis integration tests.');
        }
        self::assertTrue(extension_loaded('redis'), 'Run in the PHP image with ext-redis.');
        // Never load application Redis settings or allow a production endpoint override.
        if ($this->isolatedRedis === null) {
            $this->isolatedRedis = new \Redis();
            self::assertTrue($this->isolatedRedis->connect('127.0.0.1', 16389, 2));
            $this->isolatedRedis->setOption(\Redis::OPT_READ_TIMEOUT, 2);
        }
        $client ??= new RedisClient();
        self::assertTrue($client->connect('127.0.0.1', 16389, 2));
        $client->setReadTimeout(2);
        $this->connections[] = $client;
        return $client;
    }

    private function cookieToken(string $cookie): string
    {
        self::assertSame(1, preg_match('/^__Host-claire-oidc=([a-f0-9]{64});/', $cookie, $matches));
        $this->transactionKeys[] = 'oidc:transaction:' . hash('sha256', $matches[1]);
        return $matches[1];
    }

    private function client(
        GenericProvider $provider,
        array $jwks = [],
        string $redirectUri = 'https://claire.test/auth/callback',
    ): OidcClient
    {
        // Skip discovery entirely: these tests must never contact an identity provider.
        $client = (new ReflectionClass(OidcClient::class))->newInstanceWithoutConstructor();
        foreach ([
            'logger' => new NullLogger(),
            'settings' => new Settings(['oidc' => ['client_id' => 'claire']]),
            'genericProvider' => $provider,
            'redirectUri' => $redirectUri,
            'scopes' => ['openid'],
            'discovery' => ['issuer' => 'https://issuer.test'],
            'jwks' => $jwks,
        ] as $name => $value) {
            (new ReflectionProperty(OidcClient::class, $name))->setValue($client, $value);
        }
        return $client;
    }

    public static function invalidStates(): iterable
    {
        yield 'missing expected' => [null, 300, ['state' => 'attack', 'code' => 'code']];
        yield 'empty expected' => ['', 300, ['state' => '', 'code' => 'code']];
        yield 'missing received' => ['expected', 300, ['code' => 'code']];
        yield 'different' => ['expected', 300, ['state' => 'other', 'code' => 'code']];
        yield 'array' => ['expected', 300, ['state' => ['expected'], 'code' => 'code']];
        yield 'expired' => ['expected', -1, ['state' => 'expected', 'code' => 'code']];
        yield 'missing code' => ['expected', 300, ['state' => 'expected']];
        yield 'array code' => ['expected', 300, ['state' => 'expected', 'code' => []]];
        yield 'provider error' => ['expected', 300, ['state' => 'expected', 'error' => 'access_denied']];
    }

    #[DataProvider('invalidStates')]
    public function testInvalidCallbacksNeverExchangeTokens(?string $state, int $ttl, array $query): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects(self::never())->method('getAccessToken');
        $session = new ArraySession();
        $session->set('oidc_state', $state);
        $session->set('oidc_state_expires', time() + $ttl);
        self::assertFalse($this->client($provider)->handleCallback($session, $query)['logged']);
        self::assertFalse($session->has('oidc_state'));
        self::assertFalse($session->has('oidc_state_expires'));
    }

    public function testTransactionExpiryTamperingAndAtomicConsumption(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::exactly(3))->method('hgetall')->willReturnOnConsecutiveCalls(
            ['state' => 'expected', 'expires' => (string) (time() - 1)],
            ['state' => 'expected', 'expires' => (string) (time() + 300)],
            ['state' => 'expected', 'expires' => (string) (time() + 300)],
        );
        $redis->expects(self::exactly(3))->method('del')->willReturnOnConsecutiveCalls(1, 1, 0);
        $transactions = new OidcTransaction($redis);
        self::assertNull($transactions->consume('tampered'));
        self::assertNull($transactions->consume([]));
        self::assertNull($transactions->consume(str_repeat('a', 64)));
        self::assertSame('expected', $transactions->consume(str_repeat('b', 64)));
        self::assertNull($transactions->consume(str_repeat('b', 64)));
    }

    public function testAuthorizationStatesAreUniqueAndExpiring(): void
    {
        $client = $this->client(new GenericProvider([
            'clientId' => 'claire',
            'urlAuthorize' => 'https://issuer.test/authorize',
            'urlAccessToken' => 'https://issuer.test/token',
            'urlResourceOwnerDetails' => 'https://issuer.test/userinfo',
        ]));
        $session = new ArraySession();
        $firstUrl = $client->getAuthorizationUrl($session);
        $firstState = $session->get('oidc_state');
        self::assertNotSame('', $firstState);
        self::assertStringContainsString('state=' . $firstState, $firstUrl);
        self::assertGreaterThan(time(), $session->get('oidc_state_expires'));
        $client->getAuthorizationUrl($session);
        self::assertNotSame($firstState, $session->get('oidc_state'));
    }

    public function testHttpDevelopmentCallbackIsExplicitlyRefused(): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects(self::never())->method('getAuthorizationUrl');
        $session = new ArraySession();
        $client = $this->client($provider, redirectUri: 'http://localhost/auth/callback');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Browser OIDC requires an HTTPS callback, including in development.');
        $client->getAuthorizationUrl($session);
    }

    public function testBrowserSecretIsIndependentAndCookieIsHostOnly(): void
    {
        $state = bin2hex(random_bytes(32));
        $records = [];
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::exactly(2))->method('hset')->willReturnCallback(
            static function ($key, $data, $ttl) use (&$records, $state): int {
                self::assertSame($state, $data['state']);
                self::assertSame(OidcTransaction::TTL, $ttl);
                $records[] = $key;
                return 1;
            }
        );
        $transactions = new OidcTransaction($redis);
        $secrets = [];
        foreach ([1, 2] as $attempt) {
            $cookie = $transactions->issue($state);
            $secret = $this->cookieToken($cookie);
            self::assertNotSame($state, $secret);
            self::assertStringNotContainsString($state, $cookie);
            self::assertSame(
                '__Host-claire-oidc=' . $secret . '; Path=/; Max-Age=300; Secure; HttpOnly; SameSite=Lax',
                $cookie
            );
            self::assertStringNotContainsString('domain=', strtolower($cookie));
            self::assertSame('oidc:transaction:' . hash('sha256', $secret), $records[$attempt - 1]);
            $secrets[] = $secret;
        }
        self::assertNotSame($secrets[0], $secrets[1]);
        self::assertSame(
            '__Host-claire-oidc=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax',
            $transactions->clearCookie()
        );
    }

    public function testIsolatedRedisPublicStateCannotReplaceBrowserSecret(): void
    {
        $redis = $this->connectIsolatedRedis();
        $transactions = new OidcTransaction($redis);
        $state = bin2hex(random_bytes(32));
        $secret = $this->cookieToken($transactions->issue($state));
        self::assertNull($transactions->consume($state));
        self::assertSame(1, $this->isolatedRedis->exists($this->transactionKeys[0]));
        self::assertSame($state, $transactions->consume($secret));
        self::assertNull($transactions->consume($secret));
    }

    public function testTokenExchangeFailureStillConsumesState(): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects(self::once())->method('getAccessToken')->willThrowException(new \RuntimeException('offline'));
        $client = $this->client($provider);
        $session = new ArraySession();
        $session->set('oidc_state', 'expected');
        $session->set('oidc_state_expires', time() + 300);
        $query = ['state' => 'expected', 'code' => 'code'];
        self::assertFalse($client->handleCallback($session, $query)['logged']);
        self::assertFalse($client->handleCallback($session, $query)['logged']);
    }

    public function testStorageFailureDoesNotIssueCookie(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::once())->method('hset')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        (new OidcTransaction($redis))->issue('expected');
    }

    public static function browserBackends(): iterable
    {
        yield 'in-memory double' => [false];
        yield 'isolated Redis' => [true];
    }

    #[DataProvider('browserBackends')]
    public function testBrowserFlowWithFreshCallbackSessionAndReplayRefusal(bool $realRedis): void
    {
        $redis = $realRedis ? $this->connectIsolatedRedis() : $this->createMock(RedisClient::class);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        $config = Configuration::forAsymmetricSigner(
            new Sha256(), InMemory::plainText($privateKey), InMemory::plainText($details['key'])
        );
        $now = new DateTimeImmutable();
        $jwt = $config->builder()->withHeader('kid', 'test')->issuedBy('https://issuer.test')
            ->permittedFor('claire')->relatedTo('user-123')->issuedAt($now)->expiresAt($now->modify('+5 minutes'))
            ->withClaim('given_name', 'Test')->getToken($config->signer(), $config->signingKey())->toString();
        $encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects(self::once())->method('getAuthorizationUrl')
            ->willReturn('https://issuer.test/authorize?state=expected');
        $provider->expects(self::once())->method('getState')->willReturn('expected');
        $provider->expects(self::once())->method('getAccessToken')->with('authorization_code', [
            'code' => 'code', 'redirect_uri' => 'https://claire.test/auth/callback',
        ])->willReturn(new AccessToken(['access_token' => 'access', 'expires_in' => 300, 'id_token' => $jwt]));
        $client = $this->client($provider, ['keys' => [[
            'kid' => 'test', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
        ]]]);
        $storage = [];
        if (! $realRedis) {
            $redis->expects(self::once())->method('hset')->willReturnCallback(
                static function ($key, $data, $ttl) use (&$storage): int {
                    self::assertSame(300, $ttl);
                    $storage[$key] = $data;
                    return 1;
                }
            );
            $redis->expects(self::exactly(2))->method('hgetall')
                ->willReturnCallback(static function ($key) use (&$storage): array {
                    return $storage[$key] ?? [];
                });
            $redis->expects(self::exactly(2))->method('del')->willReturnCallback(
                static function ($key) use (&$storage): int {
                    $exists = isset($storage[$key]);
                    unset($storage[$key]);
                    return (int) $exists;
                }
            );
        }
        $auth = $this->createMock(Auth::class);
        $auth->expects(self::once())->method('login')
            ->with(self::isInstanceOf(ArraySession::class), 'user-123', self::anything())
            ->willReturnCallback(static function (ArraySession $session, string $id): void {
                $session->set(Auth::USERID, $id);
            });
        $tokens = new JwtTokenService(new Settings([
            'session' => ['lifetime' => 300, 'jwt' => ['secret' => str_repeat('s', 32)]],
        ]));
        $controller = new AuthController(new NullLogger(), $client, $auth, $tokens,
            new VueShell(), new OidcTransaction($redis),
            $this->createStub(\Doctrine\ORM\EntityManager::class),
            new \App\Services\ChatGenerationState($this->createStub(RedisClient::class), new Settings([])));
        $factory = new ServerRequestFactory();
        $initial = new ArraySession();
        $response = $controller->ssoRedirect(
            $factory->createServerRequest('GET', '/auth/sso')->withAttribute('session', $initial), new Response()
        );
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('; Path=/; Max-Age=300; Secure; HttpOnly; SameSite=Lax', $cookie);
        self::assertFalse($initial->has('oidc_state'));
        $cookieToken = $this->cookieToken($cookie);
        if ($realRedis) {
            $ttl = $this->isolatedRedis->ttl($this->transactionKeys[0]);
            self::assertGreaterThan(0, $ttl);
            self::assertLessThanOrEqual(OidcTransaction::TTL, $ttl);
        }
        $callbackSession = new ArraySession();
        $callbackSession->start();
        $request = $factory->createServerRequest('GET', '/auth/callback')
            ->withAttribute('session', $callbackSession)->withAttribute('base_url', 'https://claire.test')
            ->withCookieParams([OidcTransaction::COOKIE => $cookieToken])
            ->withQueryParams(['state' => 'expected', 'code' => 'code']);
        $response = $controller->ssoCallback($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertSame(1, preg_match('/<script id="claire-page-data" type="application\/json">(.*?)<\/script>/s', $html, $sessionMatch));
        $data = json_decode($sessionMatch[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('callback', $data['page']);
        $sessionJwt = $data['sessionToken'];
        self::assertSame('user-123', $tokens->parseSessionToken($sessionJwt)['sessionData'][Auth::USERID]);
        self::assertStringNotContainsString('minitoken', $html);
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertFalse($callbackSession->has('oidc_state'));
        self::assertSame(403, $controller->ssoCallback($request, new Response())->getStatusCode());
        self::assertSame(403, $controller->ssoCallback($request->withCookieParams([]), new Response())->getStatusCode());
    }

    public function testIsolatedRedisExpiryAndConcurrentConsumption(): void
    {
        $first = $this->connectIsolatedRedis();
        $transactions = new OidcTransaction($first);
        $token = $this->cookieToken($transactions->issue('expired'));
        $key = $this->transactionKeys[0];
        self::assertTrue($this->isolatedRedis->expireAt($key, time() - 1));
        self::assertNull($transactions->consume($token));

        $token = $this->cookieToken($transactions->issue('expired-payload'));
        $key = $this->transactionKeys[1];
        $this->isolatedRedis->hSet($key, 'expires', (string) (time() - 1));
        self::assertNull($transactions->consume($token));
        self::assertSame(0, $this->isolatedRedis->exists($key));

        $token = $this->cookieToken($transactions->issue('unique'));
        // Force the second consumer between the first consumer's HGETALL and DEL.
        $interleaved = new class extends RedisClient {
            public ?\Closure $afterRead = null;

            public function hgetall(string $key): array|false
            {
                $data = parent::hgetall($key);
                ($this->afterRead)();
                return $data;
            }
        };
        $this->connectIsolatedRedis($interleaved);
        $winner = null;
        $interleaved->afterRead = static function () use ($transactions, $token, &$winner): void {
            $winner = $transactions->consume($token);
        };
        self::assertNull((new OidcTransaction($interleaved))->consume($token));
        self::assertSame('unique', $winner);
        self::assertNull($transactions->consume($token));
    }

    public static function browserFailures(): iterable
    {
        yield 'legacy callback without cookie' => [false, false, ['state' => 'expected', 'code' => 'code'], 403];
        yield 'different state' => [true, false, ['state' => 'wrong', 'code' => 'code'], 302];
        yield 'missing state' => [true, false, ['code' => 'code'], 302];
        yield 'expired cookie transaction' => [true, true, ['state' => 'expected', 'code' => 'code'], 403];
        yield 'provider denial' => [true, false, ['state' => 'expected', 'error' => 'access_denied'], 403];
    }

    #[DataProvider('browserFailures')]
    public function testIsolatedRedisBrowserFailures(
        bool $withCookie,
        bool $expired,
        array $query,
        int $status,
    ): void {
        $redis = $this->connectIsolatedRedis();
        $transactions = new OidcTransaction($redis);
        $token = $this->cookieToken($transactions->issue('expected'));
        if ($expired) {
            self::assertTrue($this->isolatedRedis->expireAt($this->transactionKeys[0], time() - 1));
        }
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects(self::never())->method('getAccessToken');
        $auth = $this->createMock(Auth::class);
        $auth->expects(self::never())->method('login');
        $controller = new AuthController(
            new NullLogger(), $this->client($provider), $auth, new JwtTokenService(new Settings([])),
            new VueShell(), $transactions,
            $this->createStub(\Doctrine\ORM\EntityManager::class),
            new \App\Services\ChatGenerationState($this->createStub(RedisClient::class), new Settings([]))
        );
        $session = new ArraySession();
        // Legacy/header session state must never replace the browser cookie binding.
        $session->set('oidc_state', 'expected');
        $session->set('oidc_state_expires', time() + 300);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/auth/callback')
            ->withAttribute('session', $session)->withAttribute('base_url', 'https://claire.test')
            ->withCookieParams($withCookie ? [OidcTransaction::COOKIE => $token] : [])
            ->withQueryParams($query);
        $response = $controller->ssoCallback($request, new Response());
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($session->has('oidc_state'));
        if ($withCookie) {
            self::assertSame(0, $this->isolatedRedis->exists($this->transactionKeys[0]));
        }
    }
}
