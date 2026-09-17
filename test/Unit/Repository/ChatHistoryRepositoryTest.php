<?php

declare(strict_types=1);

namespace App\Test\Unit\Repository;

use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Repository\ChatHistoryRepository;
use App\Services\Auth;
use App\Services\ChatThreadLock;
use App\Services\ChatTurnJournal;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Migrations\Version20260917000000;
use NeuronAI\Chat\Messages\AssistantMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatHistoryRepositoryTest extends TestCase
{
    private Connection $connection;
    private ChatHistoryRepository $repository;
    private ChatTurnJournal $journal;

    protected function setUp(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([Settings::getAppRoot() . '/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $entityManager = new EntityManager($this->connection, $configuration);
        new SchemaTool($entityManager)->createSchema(array_map(
            $entityManager->getClassMetadata(...), [User::class, ChatHistory::class, File::class],
        ));
        $migration = new Version20260917000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            // ORM already supplies the history revision and turn columns.
            if (! str_starts_with($query->getStatement(), 'ALTER TABLE')) {
                $this->connection->executeStatement($query->getStatement());
            }
        }
        $user = new User();
        $user->setId('user');
        $entityManager->persist($user);
        $entityManager->flush();
        $this->repository = $entityManager->getRepository(ChatHistory::class);
        $this->journal = new ChatTurnJournal($this->connection);
    }

    public function testCleanupPreservesSelectedTelegramOpeningWithHiddenDisplay(): void
    {
        $session = new InMemorySession([Auth::USERID => 'user', 'threadId' => 'telegram-thread']);
        $this->journal->begin('telegram-start', 'user', 'telegram-thread', 'telegram', 'update-start');
        new UserChatHistory($session, $this->connection->getNativeConnection(), threadId: 'telegram-thread')
            ->initializeWithOpeningMessage(new AssistantMessage('Welcome'), display: false);
        $this->journal->succeed('telegram-start', 'user');
        $before = $this->connection->fetchAssociative('SELECT * FROM chat_history');
        self::assertSame('[]', $before['display_messages']);

        self::assertSame(0, $this->repository->deleteEmptyConversations('user'));

        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM chat_history'));
        self::assertNull($this->journal->get('telegram-start')['deletedAt']);
        self::assertTrue($this->journal->begin(
            'next-message', 'user', $session->get('threadId'), 'telegram', 'update-next',
        )['entered']);
    }

    public function testCleanupPreservesEmptyRolledBackDraftAndCheckpointOfRunningTurn(): void
    {
        $this->journal->begin('failed', 'user', 'failed-thread', 'web', 'failed', 'submission');
        $this->journal->rollback('failed', 'user');
        $this->journal->begin('running', 'user', 'running-thread', 'web', 'running');
        $before = $this->connection->fetchAllAssociative('SELECT * FROM chat_turn ORDER BY id');

        self::assertSame(0, $this->repository->deleteEmptyConversations('user'));

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM chat_history'));
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM chat_turn ORDER BY id'));
    }

    public function testCleanupStillDeletesUnjournalizedEmptyHistory(): void
    {
        $this->connection->insert('chat_history', [
            'user_id' => 'user', 'thread_id' => 'empty-thread', 'messages' => '[]', 'display_messages' => '[]',
            'display_messages_count' => 0,
            'created_at' => '2026-09-17 00:00:00', 'updated_at' => '2026-09-17 00:00:00',
        ]);

        self::assertSame(1, $this->repository->deleteEmptyConversations('user'));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM chat_history'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM chat_turn'));
    }

    public function testExplicitDeletionStillNeutralizesJournalAndBlocksResurrection(): void
    {
        $this->journal->begin('opening', 'user', 'thread', 'telegram', 'update');
        $lock = new ChatThreadLock($this->connection->getNativeConnection(), 'user', 'thread');
        try {
            self::assertTrue($this->repository->deleteThread('user', 'thread'));
        } finally {
            $lock->release();
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM chat_history'));
        self::assertSame('rolled_back', $this->journal->get('opening')['status']);
        self::assertNotNull($this->journal->get('opening')['deletedAt']);
        $this->expectExceptionMessage('requires recovery');
        $this->journal->begin('next', 'user', 'thread', 'telegram', 'next-update');
    }
}
