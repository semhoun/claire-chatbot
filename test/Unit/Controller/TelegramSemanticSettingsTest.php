<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\TelegramController;
use App\Entity\User;
use App\Renderer\JsonRenderer;
use App\Repository\UserRepository;
use App\Services\SemanticMemoryRegistry;
use App\Services\Settings;
use App\Services\TelegramValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Migrations\Version20260930000200;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class TelegramSemanticSettingsTest extends TestCase
{
    private Connection $sql;
    private SemanticMemoryRegistry $memory;

    protected function setUp(): void
    {
        $this->sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20260930000200($this->sql, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->sql->executeStatement($query->getStatement());
        }
        $this->sql->executeStatement('CREATE TABLE account (id VARCHAR(64), telegram_id VARCHAR(64), params TEXT)');
        $this->sql->insert('account', ['id' => 'owner', 'telegram_id' => '42',
            'params' => '{"long_term_memory_enabled":true}']);
        $this->sql->insert('account', ['id' => 'other', 'telegram_id' => '43', 'params' => '{}']);
        $this->memory = new SemanticMemoryRegistry($this->sql);
    }

    public function testPreferenceDefaultsOffAndUsesSqlOwnerNotClientUserId(): void
    {
        $controller = $this->controller();
        $read = $this->invoke($controller, ['action' => 'semantic_memory']);
        self::assertFalse($read['semanticMemory']['enabled']);
        self::assertTrue($this->invoke($controller, ['semantic_memory_enabled' => true, 'userId' => 'other'])['success']);
        self::assertTrue($this->memory->preference('owner')['enabled']);
        self::assertFalse($this->memory->preference('other')['enabled']);
        self::assertSame('{"long_term_memory_enabled":true}',
            $this->sql->fetchOne('SELECT params FROM account WHERE id = ?', ['owner']));
    }

    public function testDisableRetainsIndexedDataAndEraseIsIndependent(): void
    {
        $controller = $this->controller();
        $this->invoke($controller, ['semantic_memory_enabled' => true]);
        $this->sql->insert('semantic_memory_excerpt', [
            'id' => 'excerpt', 'user_id' => 'owner', 'thread_id' => 'thread', 'turn_id' => 'turn',
            'consent_revision' => 1, 'index_version' => 1, 'source_ids' => '["user","assistant"]',
            'content' => 'Completed new turn', 'status' => 'indexed', 'created_at' => 1, 'updated_at' => 1,
        ]);
        $this->invoke($controller, ['semantic_memory_enabled' => false]);
        self::assertSame('Completed new turn', $this->sql->fetchOne('SELECT content FROM semantic_memory_excerpt'));
        $this->invoke($controller, ['action' => 'erase_semantic_memory']);
        self::assertFalse($this->memory->preference('owner')['enabled']);
        self::assertSame('', $this->sql->fetchOne('SELECT content FROM semantic_memory_excerpt'));
        self::assertSame('invalid', $this->sql->fetchOne('SELECT status FROM semantic_memory_excerpt'));
        self::assertSame('{"long_term_memory_enabled":true}',
            $this->sql->fetchOne('SELECT params FROM account WHERE id = ?', ['owner']));
    }

    public function testDisabledFeatureAndInvalidBooleanDoNotMutateSql(): void
    {
        self::assertSame(404, $this->response($this->controller(false), ['semantic_memory_enabled' => true])->getStatusCode());
        self::assertSame(400, $this->response($this->controller(), ['semantic_memory_enabled' => 'true'])->getStatusCode());
        self::assertFalse($this->memory->preference('owner')['enabled']);
    }

    public function testInvalidSignatureAndMissingSqlMappingFailClosed(): void
    {
        self::assertSame(401, $this->response($this->controller(), [
            'initData' => 'user=42&hash=invalid', 'semantic_memory_enabled' => true,
        ])->getStatusCode());
        $this->sql->delete('account', ['id' => 'owner']);
        self::assertSame(403, $this->response($this->controller(), ['semantic_memory_enabled' => true])->getStatusCode());
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM semantic_memory_preference'));
    }

    private function controller(bool $enabled = true): TelegramController
    {
        $settings = new Settings(['telegram' => ['bot_token' => 'token'],
            'llm' => ['semanticMemory' => ['enabled' => $enabled]]]);
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findByTelegramId')->willReturn($this->createStub(User::class));
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $controller = new ReflectionClass(TelegramController::class)->newInstanceWithoutConstructor();
        foreach (['logger' => new NullLogger(), 'settings' => $settings, 'jsonRenderer' => new JsonRenderer(),
            'connection' => $this->sql, 'semanticMemory' => $this->memory,
            'telegramValidator' => new TelegramValidator(new NullLogger(), $manager, $settings)] as $name => $value) {
            new ReflectionProperty($controller, $name)->setValue($controller, $value);
        }
        // No TelegramService/session/queue: semantic operations must not enter them.
        return $controller;
    }

    private function invoke(TelegramController $controller, array $body): array
    {
        $response = $this->response($controller, $body);
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function response(TelegramController $controller, array $body): \Psr\Http\Message\ResponseInterface
    {
        $user = '{"id":42}';
        $hash = hash_hmac('sha256', 'user=' . $user, hash_hmac('sha256', 'token', 'WebAppData', true));
        $request = new ServerRequestFactory()->createServerRequest('POST', '/telegram/webapp/api')
            ->withParsedBody($body + ['initData' => http_build_query(['user' => $user, 'hash' => $hash])]);
        return $controller->api($request, new Response());
    }
}
