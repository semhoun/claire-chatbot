<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\ChatStopController;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Repository\UserRepository;
use App\Services\Auth;
use App\Services\ChatStopRequests;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Migrations\Version20260930000100;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ChatStopControllerTest extends TestCase
{
    private Connection $sql;
    private ChatStopRequests $stops;

    protected function setUp(): void
    {
        $this->sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20260930000100($this->sql, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->sql->executeStatement($query->getStatement());
        }
        $this->stops = new ChatStopRequests($this->sql);
    }

    protected function tearDown(): void
    {
        $this->sql->close();
    }

    public function testDisabledByDefaultDoesNotTouchSql(): void
    {
        $this->sql->executeStatement('DROP TABLE chat_stop_request');
        self::assertSame(404, $this->request(settings: [])->getStatusCode());
    }

    public function testUnauthenticatedRequestCannotStop(): void
    {
        self::assertSame(401, $this->request(authenticated: false)->getStatusCode());
    }

    public function testQueuedAndRepeatedRequestReturnOnlyPublicStatus(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $this->request();
            self::assertSame(202, $response->getStatusCode());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame('{"status":"queued"}', (string) $response->getBody());
        }
    }

    public function testUnknownAndOtherOwnersAndChannelsAreIndistinguishable(): void
    {
        $missing = $this->request();
        $this->stops->register('private-owner', 'thread', 'web', 'generation');
        $this->stops->register('user', 'thread', 'telegram', 'generation');
        $foreign = $this->request(['threadId' => 'thread', 'generationId' => 'generation',
            'userId' => 'private-owner', 'channel' => 'telegram']);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        self::assertSame('', (string) $foreign->getBody());
        self::assertFalse($this->stops->isRequested('private-owner', 'thread', 'web', 'generation'));
        self::assertFalse($this->stops->isRequested('user', 'thread', 'telegram', 'generation'));
    }

    public function testCompletedGenerationReturnsItsUnchangedTerminal(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        $this->sql->transactional(fn () =>
            $this->stops->arbitrateTerminal('user', 'thread', 'web', 'generation', 'succeeded'));
        $response = $this->request();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"succeeded"}', (string) $response->getBody());
        self::assertFalse($this->stops->isRequested('user', 'thread', 'web', 'generation'));
    }

    public function testMalformedIdentitiesAreRejected(): void
    {
        foreach ([null, [], 42, '', str_repeat('a', 129)] as $invalid) {
            foreach (['threadId', 'generationId'] as $field) {
                $body = ['threadId' => 'thread', 'generationId' => 'generation'];
                $body[$field] = $invalid;
                self::assertSame(400, $this->request($body)->getStatusCode());
            }
        }
        foreach ([' leading', "newline\n", 'slash/id', '123'] as $invalid) {
            self::assertSame(400,
                $this->request(['threadId' => 'thread', 'generationId' => $invalid])->getStatusCode());
        }
    }

    public function testExistingOpaqueThreadAndColonGenerationIdentitiesAreAccepted(): void
    {
        $this->stops->register('user', 'legacy/thread: 1', 'web', 'assistant:one');
        self::assertSame(202, $this->request([
            'threadId' => 'legacy/thread: 1', 'generationId' => 'assistant:one',
        ])->getStatusCode());
    }

    private function request(
        array $body = ['threadId' => 'thread', 'generationId' => 'generation'],
        bool $authenticated = true,
        array $settings = ['llm' => ['stop' => ['enabled' => true]]],
    ): \Psr\Http\Message\ResponseInterface {
        $user = new User();
        $user->setId('user');
        $users = $this->createStub(UserRepository::class);
        $users->method('getCurrentUser')->willReturn($authenticated ? $user : null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($users);
        $controller = new ChatStopController($entityManager, $this->stops, new Settings($settings));
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/stop')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, new InMemorySession([Auth::USERID => 'user']))
            ->withParsedBody($body);
        return $controller($request, new Response());
    }
}
