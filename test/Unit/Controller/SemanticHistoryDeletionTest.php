<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Brain\ChatHistory\UserChatHistory;
use App\Controller\HistoryController;
use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Renderer\ChatDataRenderer;
use App\Services\Auth;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\ChatTurnJournal;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\RedisClient;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\SemanticMemoryRegistry;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use League\Flysystem\Filesystem;
use Migrations\Version20260917000000;
use Migrations\Version20260930000200;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SemanticHistoryDeletionTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private HistoryController $controller;
    private SemanticMemoryRegistry $registry;
    private bool $sqlite;

    protected function setUp(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([Settings::getAppRoot() . '/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $driver = getenv('CLAIRE_SEMANTIC_DELETE_TEST_DRIVER') ?: 'pdo_sqlite';
        $this->sqlite = $driver === 'pdo_sqlite';
        $params = $this->sqlite ? ['memory' => true] : [
            'host' => '127.0.0.1', 'port' => (int) getenv('CLAIRE_SEMANTIC_DELETE_TEST_PORT'),
            'user' => getenv('CLAIRE_SEMANTIC_DELETE_TEST_USER'),
            'password' => getenv('CLAIRE_SEMANTIC_DELETE_TEST_PASSWORD'),
            'dbname' => getenv('CLAIRE_SEMANTIC_DELETE_TEST_DATABASE'),
        ];
        if (! $this->sqlite && ($params['dbname'] !== 'claire_test' || $params['port'] < 1)) {
            throw new \RuntimeException('Explicit disposable semantic deletion database required');
        }
        $this->connection = DriverManager::getConnection(['driver' => $driver] + $params);
        $this->entityManager = new EntityManager($this->connection, $configuration);
        $create = $this->sqlite ? 'CREATE TABLE ' : 'CREATE TEMPORARY TABLE ';
        $schemaSql = new SchemaTool($this->entityManager)->getCreateSchemaSql(array_map(
            $this->entityManager->getClassMetadata(...), [User::class, ChatHistory::class, File::class],
        ));
        foreach ($schemaSql as $sql) {
            // MySQL temporary tables cannot have foreign keys; all fixtures are connection-local.
            if (! str_starts_with($sql, 'ALTER TABLE')) {
                $this->connection->executeStatement(str_replace('CREATE TABLE ', $create, $sql));
            }
        }
        foreach ([Version20260917000000::class, Version20260930000200::class] as $class) {
            $migration = new $class($this->connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                if (! str_starts_with($query->getStatement(), 'ALTER TABLE')) {
                    $this->connection->executeStatement(str_replace('CREATE TABLE ', $create, $query->getStatement()));
                }
            }
        }
        foreach (['owner', 'other'] as $id) {
            $user = new User();
            $user->setId($id);
            $this->entityManager->persist($user);
        }
        $this->entityManager->flush();
        $this->registry = new SemanticMemoryRegistry($this->connection, enabled: true);
        $this->registry->setEnabled('owner', true);
        $this->registry->setEnabled('other', true);

        // Deployed data must still be invalidated while retrieval/ingestion are disabled.
        $settings = new Settings(['llm' => ['openai' => ['contextWindow' => 50000],
            'semanticMemory' => ['enabled' => false]], 'redis' => ['prefix' => 'test:']]);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturn([]);
        $redis->method('hset')->willReturn(1);
        $this->controller = new HistoryController(
            new ChatDataRenderer(new GeneratedFileProcessor($settings, $this->entityManager)),
            $this->entityManager,
            $settings,
            new ChatStreamPublisher($redis, new ChatStreamSubscriber($settings), $settings),
            $this->createStub(QueueDispatcherInterface::class),
            $this->createStub(Filesystem::class),
        );
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testLastExchangeInvalidatesOnlyDeletedSourcesEvenWhenGloballyDisabled(bool $claireIds): void
    {
        $first = $this->seed('owner', 'thread', 'first', $claireIds);
        $last = $this->seed('owner', 'thread', 'last', $claireIds);
        $other = $this->seed('other', 'other-thread', 'other');
        $this->registry->setEnabled('owner', false);
        $response = $this->controller->deleteLastExchange($this->request('thread'), new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Question last', json_decode((string) $response->getBody(), true,
            flags: JSON_THROW_ON_ERROR)['removedMessage']);
        self::assertSame('invalid', $this->excerptStatus($last));
        self::assertSame('pending', $this->excerptStatus($first));
        self::assertSame('pending', $this->excerptStatus($other));
        self::assertSame('', $this->connection->fetchOne('SELECT content FROM semantic_memory_excerpt WHERE id = ?', [$last]));
        self::assertNull($this->registry->source('owner', $last));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT purge_pending FROM semantic_memory_preference WHERE user_id = 'owner'",
        ));
    }

    public function testThreadDeletionInvalidatesSourcesAndPreservesOtherThreads(): void
    {
        $deleted = $this->seed('owner', 'thread', 'deleted');
        $retained = $this->seed('owner', 'retained-thread', 'retained');
        if ($this->sqlite) {
            $this->connection->executeStatement(<<<'SQL'
            CREATE TRIGGER invalidation_before_history_delete BEFORE DELETE ON chat_history
            WHEN EXISTS (SELECT 1 FROM semantic_memory_excerpt
                WHERE user_id = OLD.user_id AND thread_id = OLD.thread_id AND status <> 'invalid')
            BEGIN SELECT RAISE(ABORT, 'Sources must be invalidated before history deletion'); END
            SQL);
        }
        self::assertSame(200, $this->controller->delete($this->request('thread'), new Response())->getStatusCode());
        self::assertSame('invalid', $this->excerptStatus($deleted));
        self::assertSame('pending', $this->excerptStatus($retained));
        self::assertFalse($this->connection->fetchOne("SELECT thread_id FROM chat_history WHERE thread_id = 'thread'"));
        self::assertNull($this->registry->source('owner', $deleted));
    }

    public function testFailedBusinessDeleteRollsBackInvalidationAndJournalTombstone(): void
    {
        if (! $this->sqlite) {
            self::markTestSkipped('Failure injection uses a SQLite trigger.');
        }
        $id = $this->seed('owner', 'thread', 'turn');
        $before = $this->connection->fetchAllAssociative('SELECT * FROM semantic_memory_excerpt');
        $this->connection->executeStatement(<<<'SQL'
            CREATE TRIGGER reject_history_delete BEFORE DELETE ON chat_history
            BEGIN SELECT RAISE(ABORT, 'Synthetic business deletion failure'); END
            SQL);
        try {
            $this->controller->delete($this->request('thread'), new Response());
            self::fail('Business deletion must fail');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('Synthetic business deletion failure', $exception->getMessage());
        }
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM semantic_memory_excerpt'));
        self::assertNotNull($this->registry->source('owner', $id));
        self::assertNull($this->connection->fetchOne("SELECT deleted_at FROM chat_turn WHERE id = 'turn'"));
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT purge_pending FROM semantic_memory_preference WHERE user_id = 'owner'",
        ));
    }

    public function testWrongOwnerCannotInvalidateSources(): void
    {
        $id = $this->seed('other', 'other-thread', 'other');
        self::assertSame(400, $this->controller->delete($this->request('other-thread'), new Response())->getStatusCode());
        self::assertSame('pending', $this->excerptStatus($id));
        self::assertNotNull($this->registry->source('other', $id));
    }

    public function testLastExchangeFailureRollsBackSemanticInvalidation(): void
    {
        if (! $this->sqlite) {
            self::markTestSkipped('Failure injection uses a SQLite trigger.');
        }
        $id = $this->seed('owner', 'thread', 'turn');
        $before = $this->connection->fetchAllAssociative('SELECT * FROM semantic_memory_excerpt');
        $history = $this->connection->fetchOne("SELECT stored_messages FROM chat_history WHERE thread_id = 'thread'");
        $this->connection->executeStatement(<<<'SQL'
            CREATE TRIGGER reject_history_update BEFORE UPDATE ON chat_history
            BEGIN SELECT RAISE(ABORT, 'Synthetic history update failure'); END
            SQL);
        try {
            $this->controller->deleteLastExchange($this->request('thread'), new Response());
            self::fail('History mutation must fail');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('Synthetic history update failure', $exception->getMessage());
        }
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM semantic_memory_excerpt'));
        self::assertSame($history, $this->connection->fetchOne(
            "SELECT stored_messages FROM chat_history WHERE thread_id = 'thread'",
        ));
        self::assertNotNull($this->registry->source('owner', $id));
    }

    public function testEmptyConversationMaintenanceInvalidatesOrphanedSources(): void
    {
        $this->connection->insert('chat_history', [
            'user_id' => 'owner', 'thread_id' => 'empty-thread', 'messages' => '[]', 'display_messages' => '[]',
            'display_messages_count' => 0, 'created_at' => '2026-09-30 00:00:00', 'updated_at' => '2026-09-30 00:00:00',
        ]);
        $this->connection->insert('semantic_memory_excerpt', [
            'id' => 'orphan', 'user_id' => 'owner', 'thread_id' => 'empty-thread', 'turn_id' => 'missing-turn',
            'consent_revision' => 1, 'index_version' => 1, 'source_ids' => '["missing-source"]',
            'content' => 'Retained private text', 'status' => 'indexed', 'created_at' => 1, 'updated_at' => 1,
        ]);
        self::assertSame(1, $this->entityManager->getRepository(ChatHistory::class)->deleteEmptyConversations('owner'));
        self::assertSame('invalid', $this->excerptStatus('orphan'));
        self::assertSame('', $this->connection->fetchOne("SELECT content FROM semantic_memory_excerpt WHERE id = 'orphan'"));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT purge_pending FROM semantic_memory_preference WHERE user_id = 'owner'",
        ));
    }

    public function testPartialSemanticSchemaRefusesDeletionInsteadOfSkippingRetainedSources(): void
    {
        $id = $this->seed('owner', 'thread', 'turn');
        $before = $this->connection->fetchOne("SELECT stored_messages FROM chat_history WHERE thread_id = 'thread'");
        $this->connection->executeStatement('DROP TABLE semantic_memory_preference');
        try {
            $this->controller->deleteLastExchange($this->request('thread'), new Response());
            self::fail('Partial semantic deployment must not silently bypass invalidation');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Semantic memory schema is incomplete', $exception->getMessage());
        }
        self::assertSame('pending', $this->excerptStatus($id));
        self::assertSame($before, $this->connection->fetchOne(
            "SELECT stored_messages FROM chat_history WHERE thread_id = 'thread'",
        ));
    }

    public function testLegacySchemaAbsenceDoesNotAbortOuterTransaction(): void
    {
        $this->seed('owner', 'thread', 'turn');
        $this->connection->executeStatement('DROP TABLE semantic_memory_excerpt');
        $this->connection->executeStatement('DROP TABLE semantic_memory_preference');
        $this->connection->beginTransaction();
        try {
            self::assertSame(200, $this->controller->deleteLastExchange($this->request('thread'), new Response())->getStatusCode());
            self::assertTrue($this->connection->isTransactionActive());
            self::assertSame('[]', $this->connection->fetchOne(
                "SELECT display_messages FROM chat_history WHERE thread_id = 'thread'",
            ));
        } finally {
            $this->connection->rollBack();
        }
        self::assertNotSame('[]', $this->connection->fetchOne(
            "SELECT display_messages FROM chat_history WHERE thread_id = 'thread'",
        ));
    }

    private function request(string $thread): \Psr\Http\Message\ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('DELETE', '/history')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, new InMemorySession([Auth::USERID => 'owner']))
            ->withAttribute('threadId', $thread)->withParsedBody(['threadId' => $thread]);
    }

    private function excerptStatus(string $id): string
    {
        return $this->connection->fetchOne('SELECT status FROM semantic_memory_excerpt WHERE id = ?', [$id]);
    }

    private function seed(string $user, string $thread, string $turn, bool $claireIds = true): string
    {
        $journal = new ChatTurnJournal($this->connection);
        $journal->begin($turn, $user, $thread, 'web', $turn, atomic: function () use ($user, $turn): void {
            $this->registry->captureTurn($user, $turn);
        });
        $history = new UserChatHistory(new InMemorySession([Auth::USERID => $user]),
            $this->connection->getNativeConnection(), threadId: $thread);
        $question = new UserMessage('Question ' . $turn)->addMetadata('claire_submission_id', 'submission-' . $turn);
        $answer = new AssistantMessage('Answer ' . $turn)->addMetadata('claire_message_id', 'assistant-' . $turn);
        $history->addMessage($question);
        $history->addMessage($answer);
        $id = null;
        $journal->succeed($turn, $user, function () use ($user, $turn, $question, $answer, $claireIds, &$id): void {
            $id = $this->registry->recordSucceededTurn($user, $turn,
                $claireIds ? $question->getMetadata('claire_submission_id') : $question->getId(),
                $claireIds ? $answer->getMetadata('claire_message_id') : $answer->getId(),
                $question->getContent(), $answer->getContent());
        });
        self::assertNotNull($id);
        return $id;
    }
}
