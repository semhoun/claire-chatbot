<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Controller\SseInternalController;
use App\Entity\ChatHistory;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SseInternalTest extends TestCase
{
    private array $storage = [];
    private array $ttls = [];
    private array $generation = ['messageId' => 'message-1', 'status' => 'queued'];
    private ?ChatHistory $thread = null;
    private bool $userExists = true;
    private bool $redisAvailable = true;
    private Settings $settings;
    private JwtTokenService $tokens;
    private ArraySession $session;
    private RedisClient $redis;
    private App $app;
    private \PDO $pdo;
    private ?\Closure $onHistoryRead = null;

    protected function setUp(): void
    {
        $this->settings = new Settings([
            'base_url' => 'https://claire.test/base',
            'redis' => ['prefix' => 'test:'],
            'sse' => ['secret' => 'dedicated-internal-secret', 'duration' => 1800],
            'session' => ['lifetime' => 120, 'jwt' => ['secret' => str_repeat('s', 32)]],
            'llm' => ['openai' => ['contextWindow' => 50000]],
        ]);
        $this->redis = $this->createStub(RedisClient::class);
        $this->redis->method('get')->willReturnCallback(function (string $key): string|false {
            if (! $this->redisAvailable) {
                throw new \RuntimeException('Redis unavailable');
            }
            return $this->storage[$key] ?? false;
        });
        $this->redis->method('setex')->willReturnCallback(function (string $key, int $ttl, string $value): bool {
            $this->storage[$key] = $value;
            $this->ttls[$key] = $ttl;
            return $this->redisAvailable;
        });
        $this->redis->method('del')->willReturnCallback(function (array|string $key): int|false {
            unset($this->storage[$key]);
            return $this->redisAvailable ? 1 : false;
        });
        $this->redis->method('hgetall')->willReturnCallback(function (): array|false {
            return $this->redisAvailable ? $this->generation : false;
        });
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT, '
            . 'display_messages TEXT, display_messages_count INTEGER DEFAULT 0, title TEXT, summary TEXT, '
            . 'revision INTEGER NOT NULL DEFAULT 0, current_turn_id TEXT)');
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params): array|false {
            if ($sql === 'SELECT * FROM chat_turn WHERE id = ?') {
                return false;
            }
            self::assertSame(['thread-1', 'user-1'], $params);
            return $this->userExists ? ['user_id' => $this->thread?->getUser()->getId()] : false;
        });
        $connection->method('getNativeConnection')->willReturnCallback(function (): \PDO {
            if ($this->onHistoryRead !== null) {
                ($this->onHistoryRead)();
            }
            return $this->pdo;
        });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $this->tokens = new JwtTokenService($this->settings);
        $remember = new RememberSession($this->redis, $this->settings);
        $state = new ChatGenerationState($this->redis, $this->settings);
        $snapshots = new ChatSnapshot($state, $entityManager,
            new ChatDataRenderer(new GeneratedFileProcessor($this->settings, $entityManager)), $this->settings);
        $access = new SseAccess($this->tokens, $remember, $entityManager, $state, $this->settings);
        $controller = new SseInternalController(new SseAuthorization($access, $snapshots, $this->redis, $this->settings));
        $this->app = AppFactory::create();
        foreach (['/open', '/snapshot', '/close'] as $path) {
            $this->app->post($path, $controller);
        }
        $this->app->addRoutingMiddleware();
        $this->app->add(new SseInternalMiddleware($this->settings));
        $this->session = new ArraySession();
        $this->session->start();
        $this->session->set(Auth::USERID, 'user-1');
        $this->session->set(Auth::AUTHENTICATED, true);
    }

    public function testOpenStoresOnlyMinimalContextWithFixedDeadlineAndTtl(): void
    {
        $this->session->set('private-session-data', 'must-not-survive');
        $response = $this->request('/open', $this->input('header'));
        self::assertSame(200, $response->getStatusCode());
        $open = $this->decode($response);
        self::assertSame(['authorization', 'userId', 'threadId', 'sessionId', 'openedAt', 'deadline', 'remember'],
            array_keys($open));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $open['authorization']);
        self::assertSame('user-1', $open['userId']);
        self::assertSame(1800.0, $open['deadline'] - $open['openedAt']);
        self::assertNull($open['remember']);
        self::assertSame([1800], array_values($this->ttls));
        self::assertStringNotContainsString('must-not-survive', implode('', $this->storage));
        $stored = $this->storage;
        $snapshot = $this->request('/snapshot', ['authorization' => $open['authorization']]);
        self::assertSame(200, $snapshot->getStatusCode());
        self::assertSame($stored, $this->storage);
        self::assertSame(['messageId' => 'message-1', 'status' => 'queued'], $this->decode($snapshot)['generation']);
        self::assertSame('message-1', $this->decode($snapshot)['generationMessageId']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM chat_history')->fetchColumn());
        self::assertSame(204, $this->request('/close', ['authorization' => $open['authorization']])->getStatusCode());
        self::assertSame([], $this->storage);
        self::assertSame(401, $this->request('/snapshot', ['authorization' => $open['authorization']])->getStatusCode());
    }

    public static function credentials(): iterable
    {
        yield 'header JWT' => ['header', [], 200];
        yield 'simple capability' => ['capability', [], 200];
        yield 'batch capability' => ['batch', [], 200];
        yield 'general JWT cannot be URL capability' => ['header', ['credentialType' => 'capability'], 401];
        yield 'wrong tab' => ['capability', ['sessionId' => 'other'], 403];
        yield 'wrong thread' => ['batch', ['threadId' => 'other'], 403];
        yield 'missing base path' => ['capability', ['path' => '/brain/stream'], 400];
        yield 'encoded path' => ['header', ['path' => '/base/brain/%73tream'], 400];
        yield 'trailing slash' => ['header', ['path' => '/base/brain/stream/'], 400];
        yield 'absolute URL' => ['header', ['path' => 'https://claire.test/base/brain/stream'], 400];
        yield 'query in path' => ['header', ['path' => '/base/brain/stream?token=secret'], 400];
        yield 'traversal' => ['header', ['path' => '/base/../base/brain/stream'], 400];
        yield 'public method' => ['header', ['method' => 'POST'], 400];
        yield 'bad scope type' => ['header', ['threadId' => []], 400];
        yield 'scope whitespace' => ['header', ['sessionId' => ' tab'], 400];
        yield 'scope slash' => ['header', ['sessionId' => 'a/b'], 400];
        yield 'unknown field' => ['header', ['userId' => 'other'], 400];
        yield 'invalid token' => ['header', ['credential' => 'invented'], 401];
        yield 'invalid credential type' => ['header', ['credentialType' => 'query'], 400];
    }

    #[DataProvider('credentials')]
    public function testCredentialAndPublicContextValidation(string $type, array $overrides, int $status): void
    {
        $response = $this->request('/open', array_replace($this->input($type), $overrides));
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('X-Claire-Token'));
        if ($status !== 200) {
            self::assertSame('', (string) $response->getBody());
            self::assertSame([], $this->storage);
        }
    }

    public function testWrongAudienceAndSignatureNeverAuthorize(): void
    {
        $otherTokens = new JwtTokenService(new Settings([
            'session' => ['lifetime' => 120, 'jwt' => ['secret' => str_repeat('x', 32)]],
        ]));
        foreach ([
            [$this->tokens->generateFileToken($this->session, 'file-1'), 403],
            [$this->tokens->generateMiniToken($this->session), 401],
            [$otherTokens->generateSessionToken($this->session), 401],
            [$this->tokens->generateSessionToken($this->session, -10), 401],
        ] as [$credential, $status]) {
            self::assertSame($status, $this->request('/open',
                array_replace($this->input('header'), ['credential' => $credential]))->getStatusCode());
        }
    }

    public function testSnapshotsOutliveOpeningJwtButCannotReopenWithExpiredJwt(): void
    {
        $input = array_replace($this->input('header'), [
            'credential' => $this->tokens->generateSessionToken($this->session, 1),
        ]);
        $open = $this->decode($this->request('/open', $input));
        usleep(1_100_000);
        self::assertSame(401, $this->request('/open', $input)->getStatusCode());
        self::assertSame(200, $this->request('/snapshot', ['authorization' => $open['authorization']])->getStatusCode());
    }

    public function testConversationAndUserAccessAreRechecked(): void
    {
        $open = $this->decode($this->request('/open', $this->input()));
        $other = new User();
        $other->setId('other');
        $this->thread = new ChatHistory();
        $this->thread->setUser($other);
        self::assertSame(403, $this->request('/open', $this->input())->getStatusCode());
        self::assertSame(403, $this->request('/snapshot', ['authorization' => $open['authorization']])->getStatusCode());
        $this->thread = null;
        $this->generation = [];
        self::assertSame(403, $this->request('/open', $this->input())->getStatusCode());
        self::assertSame(403, $this->request('/snapshot', ['authorization' => $open['authorization']])->getStatusCode());
        $this->userExists = false;
        self::assertSame(401, $this->request('/open', $this->input())->getStatusCode());
        self::assertSame(401, $this->request('/snapshot', ['authorization' => $open['authorization']])->getStatusCode());
    }

    public function testOwnedConversationNeedsNoGenerationButMissingConversationDoes(): void
    {
        $owner = new User();
        $owner->setId('user-1');
        $this->thread = new ChatHistory();
        $this->thread->setUser($owner);
        $this->generation = [];
        $open = $this->decode($this->request('/open', $this->input()));
        $snapshot = $this->decode($this->request('/snapshot', ['authorization' => $open['authorization']]));
        self::assertSame([], $snapshot['messages']);
        self::assertSame(['messageId' => '', 'status' => ''], $snapshot['generation']);
        self::assertNull($snapshot['generationMessageId']);
        $this->thread = null;
        foreach (['queued', 'running', 'done', 'error'] as $status) {
            $this->generation = ['messageId' => 'generation', 'status' => $status];
            self::assertSame(200, $this->request('/open', $this->input())->getStatusCode());
        }
        $this->generation = ['messageId' => 'generation', 'status' => 'deleted'];
        self::assertSame(403, $this->request('/open', $this->input())->getStatusCode());
    }

    public function testSnapshotRecapturesTerminalGenerationDuringSqlRead(): void
    {
        $open = $this->decode($this->request('/open', $this->input()));
        $reads = 0;
        $this->onHistoryRead = function () use (&$reads): void {
            $reads++;
            $this->generation = ['messageId' => 'message-1', 'status' => 'done'];
        };
        $snapshot = $this->decode($this->request('/snapshot', ['authorization' => $open['authorization']]));
        self::assertSame(2, $reads);
        self::assertSame(['messageId' => 'message-1', 'status' => 'done'], $snapshot['generation']);
        self::assertSame('message-1', $snapshot['generationMessageId']);
        self::assertNull($snapshot['activeMessageId']);
        self::assertFalse($snapshot['responding']);
    }

    public function testAuthorizationClosedOrExpiredDuringSqlNeverReturnsSnapshot(): void
    {
        foreach (['close', 'expire'] as $action) {
            $this->onHistoryRead = null;
            $open = $this->decode($this->request('/open', $this->input()));
            $this->onHistoryRead = function () use ($action): void {
                foreach ($this->storage as $key => $value) {
                    if ($action === 'close') {
                        unset($this->storage[$key]);
                        continue;
                    }
                    $context = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                    $context['deadline'] = microtime(true) - 1;
                    $this->storage[$key] = json_encode($context, JSON_THROW_ON_ERROR);
                }
            };
            $response = $this->request('/snapshot', ['authorization' => $open['authorization']]);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
        }
    }

    public function testRememberRevocationExpiryAndMetadataMismatchDenyWithoutSnapshot(): void
    {
        $id = str_repeat('a', 64);
        $expires = time() + 60;
        $key = 'test:auth:remember:' . $id;
        $this->session->set(RememberSession::ID, $id);
        $this->session->set(RememberSession::EXPIRES, $expires);
        $this->storage[$key] = json_encode(['user_id' => 'user-1', 'expires' => $expires], JSON_THROW_ON_ERROR);
        $open = $this->decode($this->request('/open', $this->input()));
        self::assertSame(['id' => $id, 'expires' => $expires], $open['remember']);
        foreach ([null, ['user_id' => 'other', 'expires' => $expires],
            ['user_id' => 'user-1', 'expires' => $expires + 1],
            ['user_id' => 'user-1', 'expires' => time() - 1]] as $data) {
            if ($data === null) {
                unset($this->storage[$key]);
            } else {
                $this->storage[$key] = json_encode($data, JSON_THROW_ON_ERROR);
            }
            $response = $this->request('/snapshot', ['authorization' => $open['authorization']]);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
            self::assertSame(401, $this->request('/open', $this->input())->getStatusCode());
        }
    }

    public function testExpiredInventedMalformedAndCrossScopeAuthorizationsAreRejected(): void
    {
        $open = $this->decode($this->request('/open', $this->input()));
        foreach (['invented', str_repeat('b', 64), $this->input()['credential']] as $authorization) {
            self::assertSame(401, $this->request('/snapshot', ['authorization' => $authorization])->getStatusCode());
        }
        self::assertSame(400, $this->request('/snapshot', [
            'authorization' => $open['authorization'], 'threadId' => 'other',
        ])->getStatusCode());
        $key = array_key_first($this->storage);
        $context = json_decode($this->storage[$key], true, flags: JSON_THROW_ON_ERROR);
        $context['deadline'] = microtime(true) - 1;
        $this->storage[$key] = json_encode($context, JSON_THROW_ON_ERROR);
        self::assertSame(401, $this->request('/snapshot', ['authorization' => $open['authorization']])->getStatusCode());
        $this->storage[$key] = '{broken';
        self::assertSame(401, $this->request('/snapshot', ['authorization' => $open['authorization']])->getStatusCode());
    }

    public function testRememberRevokedDuringRenderingCannotLeakSnapshot(): void
    {
        $id = str_repeat('a', 64);
        $expires = time() + 60;
        $key = 'test:auth:remember:' . $id;
        $this->session->set(RememberSession::ID, $id);
        $this->session->set(RememberSession::EXPIRES, $expires);
        $this->storage[$key] = json_encode(['user_id' => 'user-1', 'expires' => $expires], JSON_THROW_ON_ERROR);
        $open = $this->decode($this->request('/open', $this->input()));
        $this->onHistoryRead = function () use ($key): void {
            unset($this->storage[$key]);
        };
        $response = $this->request('/snapshot', ['authorization' => $open['authorization']]);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testOpaqueAuthorizationIsNotAPublicCredential(): void
    {
        $open = $this->decode($this->request('/open', $this->input()));
        $middleware = new JwtSessionMiddleware($this->tokens, $this->settings,
            new RememberSession($this->redis, $this->settings));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = new ServerRequestFactory()->createServerRequest('GET', '/history/list')
            ->withHeader('X-Claire-Auth', $open['authorization']);
        self::assertSame(401, $middleware->process($request, $handler)->getStatusCode());
    }

    public function testRedisFailureFailsClosed(): void
    {
        $open = $this->decode($this->request('/open', $this->input()));
        $this->redisAvailable = false;
        foreach (['/snapshot', '/close'] as $path) {
            $response = $this->request($path, ['authorization' => $open['authorization']]);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
        }
        self::assertSame(503, $this->request('/open', $this->input())->getStatusCode());
    }

    public function testPrivateBoundaryRejectsPublicListenerAndForgedProxyHeaders(): void
    {
        foreach ([['SERVER_PORT' => '443'], ['SERVER_ADDR' => '0.0.0.0'], ['REMOTE_ADDR' => '192.0.2.1']] as $server) {
            self::assertSame(403, $this->request('/open', $this->input(), server: $server)->getStatusCode());
        }
        self::assertSame(403, $this->request('/open', $this->input(), secret: 'wrong')->getStatusCode());
        self::assertSame(403, $this->request('/open', $this->input(), secret: '')->getStatusCode());
        self::assertSame(400, $this->request('/open?credential=secret', $this->input())->getStatusCode());
        self::assertSame(405, $this->request('/open', $this->input(), method: 'GET')->getStatusCode());
        self::assertSame(404, $this->request('/brain/stream', $this->input())->getStatusCode());
        self::assertSame(404, $this->request('/%6fpen', $this->input())->getStatusCode());
    }

    public function testMalformedAndOversizedBodiesAndWrongContentTypesAreRejected(): void
    {
        foreach (['{invalid', 'null', '[]', '"credential"', str_repeat(' ', 16385) . '{}'] as $raw) {
            self::assertSame(400, $this->request('/open', [], raw: $raw)->getStatusCode());
        }
        self::assertSame(400, $this->request('/open', $this->input(), contentType: 'text/plain')->getStatusCode());
    }

    public function testPublicBrainRoutesContainNeitherStreamNorInternalOperations(): void
    {
        $app = AppFactory::create();
        $routes = require Settings::getAppRoot() . '/config/routes/brain.php';
        $routes($app);
        self::assertSame(['/brain/messages', '/brain/turn/{submissionId}', '/brain/audio'], array_values(array_map(
            static fn ($route): string => $route->getPattern(), $app->getRouteCollector()->getRoutes(),
        )));
        self::assertFalse(method_exists(\App\Controller\BrainController::class, 'stream'));
    }

    public function testMissingInternalSecretRefusesStartup(): void
    {
        $this->expectException(\RuntimeException::class);
        new SseInternalMiddleware(new Settings(['sse' => ['secret' => '']]));
    }

    private function input(string $type = 'capability'): array
    {
        return [
            'credential' => match ($type) {
                'header' => $this->tokens->generateSessionToken($this->session),
                'batch' => $this->tokens->generateResourcesToken($this->session, [
                    ['type' => 'file', 'fileId' => 'file-1'],
                    ['type' => 'stream', 'threadId' => 'thread-1', 'sessionId' => 'tab-1'],
                ], time() + 60),
                default => $this->tokens->generateStreamToken($this->session, 'thread-1', 'tab-1'),
            },
            'credentialType' => $type === 'header' ? 'header' : 'capability',
            'path' => '/base/brain/stream', 'method' => 'GET', 'threadId' => 'thread-1', 'sessionId' => 'tab-1',
        ];
    }

    private function request(
        string $path,
        array $body,
        string $secret = 'dedicated-internal-secret',
        array $server = [],
        string $method = 'POST',
        ?string $raw = null,
        string $contentType = 'application/json',
    ): ResponseInterface {
        $request = new ServerRequestFactory()->createServerRequest($method, 'http://127.0.0.1:8082' . $path,
            array_replace(['SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => '8082', 'REMOTE_ADDR' => '127.0.0.1'], $server))
            ->withHeader('Content-Type', $contentType)->withHeader('X-Claire-Sse-Secret', $secret)
            ->withHeader('X-Forwarded-For', '127.0.0.1')->withHeader('X-Forwarded-Port', '8082');
        $request->getBody()->write($raw ?? json_encode($body, JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        return $this->app->handle($request);
    }

    private function decode(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
