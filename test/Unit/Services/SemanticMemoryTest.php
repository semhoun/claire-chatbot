<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Job\SemanticMemory\IndexTurnJob;
use App\Job\SemanticMemory\SweepJob;
use App\Services\SemanticMemoryRegistry;
use App\Services\SemanticMemoryService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260930000200;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class SyntheticSemanticEmbeddings implements EmbeddingsProviderInterface
{
    public int $calls = 0;
    public ?\Closure $duringCall = null;
    public bool $fail = false;
    public array $vector = [1.0, 0.0, 0.0];

    public function embedText(string $text): array
    {
        ++$this->calls;
        if ($this->duringCall !== null) {
            ($this->duringCall)();
        }
        if ($this->fail) {
            throw new RuntimeException('Synthetic provider failure');
        }
        return $this->vector;
    }

    public function embedDocument(Document $document): Document
    {
        return $document->setEmbedding($this->embedText($document->getContent()));
    }

    public function embedDocuments(array $documents): array
    {
        return array_map($this->embedDocument(...), $documents);
    }
}

final class SemanticSqlLog extends AbstractLogger
{
    public array $queries = [];
    public ?\Closure $beforeQuery = null;

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if (is_string($context['sql'] ?? null)) {
            $this->queries[] = $context['sql'];
            if ($this->beforeQuery !== null) {
                ($this->beforeQuery)($context['sql']);
            }
        }
    }
}

final class SemanticMemoryTest extends TestCase
{
    private Connection $sql;
    private SemanticMemoryRegistry $registry;
    private SemanticMemoryService $memory;
    private SyntheticSemanticEmbeddings $embeddings;
    private string $directory;
    private SemanticSqlLog $sqlLog;

    protected function setUp(): void
    {
        $driver = getenv('CLAIRE_SEMANTIC_TEST_DRIVER') ?: 'pdo_sqlite';
        $params = $driver === 'pdo_sqlite' ? ['memory' => true] : [
            'host' => '127.0.0.1', 'port' => (int) getenv('CLAIRE_SEMANTIC_TEST_PORT'),
            'user' => getenv('CLAIRE_SEMANTIC_TEST_USER'), 'password' => getenv('CLAIRE_SEMANTIC_TEST_PASSWORD'),
            'dbname' => getenv('CLAIRE_SEMANTIC_TEST_DATABASE'),
        ];
        if ($driver !== 'pdo_sqlite' && ($params['port'] < 1 || $params['dbname'] !== 'claire_test')) {
            throw new RuntimeException('Explicit disposable semantic test database required');
        }
        $this->sqlLog = new SemanticSqlLog();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->sqlLog)]);
        $this->sql = DriverManager::getConnection(['driver' => $driver] + $params, $configuration);
        $create = $driver === 'pdo_sqlite' ? 'CREATE TABLE ' : 'CREATE TEMPORARY TABLE ';
        $collation = match ($driver) {
            'pdo_sqlite' => ' COLLATE NOCASE',
            'pdo_mysql' => ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            default => '',
        };
        $execute = function (string $sql) use ($create, $collation): void {
            $sql = preg_replace('/VARCHAR\(\d+\)/', '$0' . $collation, $sql);
            $this->sql->executeStatement(str_replace('CREATE TABLE ', $create, $sql));
        };
        $execute('CREATE TABLE account (id VARCHAR(255) PRIMARY KEY)');
        foreach (['alice', 'bob', 'mallory'] as $owner) {
            $this->sql->insert('account', ['id' => $owner]);
        }
        $execute('CREATE TABLE chat_history (user_id VARCHAR(255), thread_id VARCHAR(128) UNIQUE)');
        $execute('CREATE TABLE chat_turn (id VARCHAR(128) PRIMARY KEY, user_id VARCHAR(255),'
            . ' thread_id VARCHAR(128), status VARCHAR(16), deleted_at BIGINT DEFAULT NULL)');
        $migration = new Version20260930000200($this->sql, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $execute($query->getStatement());
        }
        $this->registry = new SemanticMemoryRegistry($this->sql, enabled: true);
        $this->embeddings = new SyntheticSemanticEmbeddings();
        $this->directory = '/tmp/kilo/semantic-memory-test-' . bin2hex(random_bytes(8));
        $this->memory = $this->service();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
        $this->sql->close();
    }

    public function testDefaultOffAndNoRetroactiveIngestion(): void
    {
        self::assertSame(['enabled' => false, 'revision' => 0, 'indexVersion' => 1], $this->registry->preference('alice'));
        $this->turn('old');
        $this->registry->setEnabled('alice', true);
        $this->sql->transactional(fn () => $this->record('old'));
        $this->memory->sweep();
        self::assertSame([], $this->registry->pending());
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        self::assertSame(0, $this->embeddings->calls);
    }

    public function testCaseAliasedOwnerCannotMutateReadOrPublishAnotherOwnersMemory(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->turn('indexed');
        $this->memory->sweep();
        $id = $this->turn('pending');
        $this->sql->update('semantic_memory_excerpt', ['updated_at' => 1], ['id' => $id]);
        $preferences = $this->sql->fetchAllAssociative('SELECT * FROM semantic_memory_preference');
        $excerpts = $this->sql->fetchAllAssociative('SELECT * FROM semantic_memory_excerpt ORDER BY id');
        $mutations = [
            fn () => $this->registry->setEnabled('ALICE', false),
            fn () => $this->registry->erase('ALICE'),
            fn () => $this->sql->transactional(fn () =>
                $this->registry->invalidateSources('ALICE', 'thread-pending')),
            fn () => $this->sql->transactional(fn () => $this->registry->lock('ALICE')),
            fn () => $this->sql->transactional(fn () => $this->registry->captureTurn('ALICE', 'pending')),
            fn () => $this->sql->transactional(fn () => $this->record('pending', 'ALICE')),
        ];
        foreach ($mutations as $mutation) {
            try {
                $mutation();
            } catch (RuntimeException) {
                // CI databases reject the alias; case-sensitive databases may safely find no owner.
            }
            self::assertSame($preferences, $this->sql->fetchAllAssociative('SELECT * FROM semantic_memory_preference'));
            self::assertSame($excerpts, $this->sql->fetchAllAssociative(
                'SELECT * FROM semantic_memory_excerpt ORDER BY id',
            ));
        }
        self::assertNull($this->registry->source('ALICE', $id));
        self::assertNull($this->registry->source('alice', strtoupper($id)));
        self::assertSame([], $this->registry->threads('ALICE', 'other'));
        self::assertFalse($this->memory->index('ALICE', $id));
        self::assertSame('', $this->memory->recall('ALICE', 'other', 'query'));
        $this->memory->purge('ALICE');
        self::assertFileExists($this->indexPath());
        self::assertSame(1, $this->embeddings->calls);
        self::assertSame($preferences, $this->sql->fetchAllAssociative('SELECT * FROM semantic_memory_preference'));
        self::assertSame($excerpts, $this->sql->fetchAllAssociative('SELECT * FROM semantic_memory_excerpt ORDER BY id'));
    }

    public function testPreferenceCreationRequiresExactExistingSqlAccountIdentity(): void
    {
        foreach (['ALICE', 'nonexistent'] as $owner) {
            try {
                $this->registry->setEnabled($owner, true);
                self::fail('Alias or missing account must not create a preference');
            } catch (RuntimeException $error) {
                self::assertSame('Semantic memory account mismatch', $error->getMessage());
            }
        }
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM semantic_memory_preference'));
        $this->registry->setEnabled('alice', true);
        self::assertTrue($this->registry->preference('alice')['enabled']);
    }

    public function testSourceJoinsRequireByteExactHistoryTurnAndPreferenceOwners(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('new');
        foreach (['chat_history', 'chat_turn', 'semantic_memory_preference'] as $table) {
            $this->sql->update($table, ['user_id' => 'ALICE'], ['user_id' => 'alice']);
            self::assertNull($this->registry->source('alice', $id));
            self::assertSame([], $this->registry->pending());
            self::assertSame([], $this->registry->threads('alice', 'other'));
            self::assertFalse($this->memory->index('alice', $id));
            $this->sql->update($table, ['user_id' => 'alice'], ['user_id' => 'ALICE']);
        }
        self::assertSame(0, $this->embeddings->calls);
        self::assertTrue($this->memory->index('alice', $id));
    }

    public function testDeletedOrCaseAliasedAccountCannotAuthorizeRetainedSources(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('indexed');
        $this->memory->sweep();
        $pending = $this->turn('pending');
        $this->sql->update('account', ['id' => 'ALICE'], ['id' => 'alice']);
        self::assertNull($this->registry->source('alice', $id));
        self::assertSame([], $this->registry->pending());
        self::assertSame([], $this->registry->threads('alice', 'other'));
        self::assertFalse($this->memory->index('alice', $pending));
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        $this->sql->delete('account', ['id' => 'ALICE']);
        self::assertFalse($this->memory->index('alice', $pending));
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        self::assertSame(1, $this->embeddings->calls);
        self::assertTrue($this->memory->purge('alice'));
        self::assertFileDoesNotExist($this->indexPath());
    }

    public function testAliasedTurnAndThreadIdsCannotCaptureCompleteOrInvalidateSources(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('new', status: 'running');
        $this->sql->transactional(function (): void {
            $this->registry->captureTurn('alice', 'NEW');
            $this->sql->update('chat_turn', ['status' => 'succeeded'], ['id' => 'new']);
            $this->record('NEW');
            $this->registry->invalidateSources('alice', 'THREAD-new');
        });
        self::assertSame('captured', $this->sql->fetchOne(
            'SELECT status FROM semantic_memory_excerpt WHERE id = ?', [$id],
        ));
        self::assertSame(1, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM semantic_memory_excerpt'));
        self::assertSame([], $this->registry->pendingPurges());
        $this->sql->transactional(fn () => $this->record('new'));
        self::assertCount(1, $this->registry->pending());
    }

    public function testAccountDeletionDuringEmbeddingOrRetrievalRejectsPublication(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('new');
        $this->embeddings->duringCall = fn () => $this->sql->delete('account', ['id' => 'alice']);
        self::assertFalse($this->memory->index('alice', $id));
        self::assertFileDoesNotExist($this->indexPath());
        $this->sql->insert('account', ['id' => 'alice']);
        $this->embeddings->duringCall = null;
        self::assertTrue($this->memory->index('alice', $id));
        $this->embeddings->duringCall = fn () => $this->sql->delete('account', ['id' => 'alice']);
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
    }

    public function testDisabledSweepErasesOrphanSqlAndFilesOnceWithoutEmbeddingProvider(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->registry->setEnabled('bob', true);
        $oldId = $this->turn('alice-indexed');
        $this->turn('bob-indexed', 'bob');
        $this->memory->sweep();
        $this->turn('alice-pending');
        $this->turn('alice-captured', status: 'running');
        $calls = $this->embeddings->calls;
        $before = $this->registry->preference('alice');
        $bob = $this->sql->fetchAllAssociative(
            'SELECT * FROM semantic_memory_excerpt WHERE user_id = ?', ['bob'],
        );
        $this->sql->delete('account', ['id' => 'alice']);
        self::assertSame(['alice'], $this->registry->orphanOwners());
        $disabled = new SemanticMemoryService($this->sql, $this->registry, null,
            new NullLogger(), $this->directory, '', 0);
        $disabled->sweep();
        self::assertSame([
            'enabled' => false, 'revision' => $before['revision'] + 1, 'indexVersion' => $before['indexVersion'] + 1,
        ], $this->registry->preference('alice'));
        self::assertSame(0, (int) $this->sql->fetchOne(
            "SELECT COUNT(*) FROM semantic_memory_excerpt WHERE user_id = ?"
            . " AND (status <> 'invalid' OR content <> '' OR source_ids <> '[]')", ['alice'],
        ));
        self::assertSame(3, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_turn WHERE user_id = ?', ['alice']));
        self::assertSame(3, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_history WHERE user_id = ?', ['alice']));
        self::assertFileDoesNotExist($this->indexPath());
        self::assertCount(1, glob($this->directory . '/' . hash('sha256', 'bob') . '/*.store'));
        self::assertSame($bob, $this->sql->fetchAllAssociative(
            'SELECT * FROM semantic_memory_excerpt WHERE user_id = ?', ['bob'],
        ));
        self::assertSame([], $this->registry->orphanOwners());
        self::assertSame([], $this->registry->pendingPurges());
        $preferences = $this->sql->fetchAllAssociative('SELECT * FROM semantic_memory_preference ORDER BY user_id');
        $excerpts = $this->sql->fetchAllAssociative('SELECT * FROM semantic_memory_excerpt ORDER BY id');
        $disabled->sweep();
        self::assertSame($preferences, $this->sql->fetchAllAssociative(
            'SELECT * FROM semantic_memory_preference ORDER BY user_id',
        ));
        self::assertSame($excerpts, $this->sql->fetchAllAssociative('SELECT * FROM semantic_memory_excerpt ORDER BY id'));
        self::assertSame($calls, $this->embeddings->calls);

        $this->sql->insert('account', ['id' => 'alice']);
        self::assertFalse($this->registry->preference('alice')['enabled']);
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        self::assertFalse($this->memory->index('alice', $oldId));
        $this->registry->setEnabled('alice', true);
        $this->memory->sweep();
        self::assertSame($calls, $this->embeddings->calls);
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        $this->turn('alice-recreated');
        $this->memory->sweep();
        self::assertSame($calls + 1, $this->embeddings->calls);
        self::assertStringContainsString('thread-alice-recreated', $this->memory->recall('alice', 'other', 'query'));
    }

    public function testOrphanCleanupIsBoundedAndRechecksAccountExistence(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->registry->setEnabled('bob', true);
        $this->turn('alice');
        $this->turn('bob', 'bob');
        $this->memory->sweep();
        $this->sql->delete('account', ['id' => 'alice']);
        $this->sql->delete('account', ['id' => 'bob']);
        self::assertSame(['alice'], $this->registry->orphanOwners(1));
        $this->sql->insert('account', ['id' => 'alice']);
        $before = $this->registry->preference('alice');
        self::assertFalse($this->registry->eraseOrphan('alice'));
        self::assertSame($before, $this->registry->preference('alice'));
        self::assertFileExists($this->indexPath());
        $this->sql->delete('account', ['id' => 'alice']);
        $disabled = new SemanticMemoryService($this->sql, $this->registry, null,
            new NullLogger(), $this->directory, '', 0);
        $disabled->sweep(1);
        self::assertFalse($this->registry->preference('alice')['enabled']);
        self::assertTrue($this->registry->preference('bob')['enabled']);
        self::assertSame(['bob'], $this->registry->orphanOwners(1));
        $disabled->sweep(1);
        self::assertFalse($this->registry->preference('bob')['enabled']);
        self::assertSame([], $this->registry->orphanOwners());
        self::assertSame([], glob($this->directory . '/*/*.store'));
        self::assertSame(2, $this->embeddings->calls);
    }

    public function testOrphanCleanupUsesExactAccountIdentityAndDoesNotRepeatWhilePurgePending(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->turn('new');
        $this->memory->sweep();
        $this->sql->update('account', ['id' => 'ALICE'], ['id' => 'alice']);
        self::assertSame(['alice'], $this->registry->orphanOwners());
        self::assertTrue($this->registry->eraseOrphan('alice'));
        $preference = $this->registry->preference('alice');
        self::assertSame(['alice'], $this->registry->pendingPurges());
        self::assertSame([], $this->registry->orphanOwners());
        self::assertFalse($this->registry->eraseOrphan('alice'));
        self::assertSame($preference, $this->registry->preference('alice'));
        self::assertSame('ALICE', $this->sql->fetchOne('SELECT id FROM account WHERE id = ?', ['ALICE']));
        $disabled = new SemanticMemoryService($this->sql, $this->registry, null,
            new NullLogger(), $this->directory, '', 0);
        $disabled->sweep();
        self::assertFileDoesNotExist($this->indexPath());
        self::assertSame($preference, $this->registry->preference('alice'));
    }

    public function testOrphanCleanupFencesAnEmbeddingJobAlreadyInFlight(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('in-flight');
        $disabled = new SemanticMemoryService($this->sql, $this->registry, null,
            new NullLogger(), $this->directory, '', 0);
        $this->embeddings->duringCall = function () use ($disabled): void {
            $this->sql->delete('account', ['id' => 'alice']);
            $disabled->sweep();
            $this->sql->insert('account', ['id' => 'alice']);
        };
        self::assertFalse($this->memory->index('alice', $id));
        self::assertFalse($this->registry->preference('alice')['enabled']);
        self::assertSame(2, $this->registry->preference('alice')['indexVersion']);
        self::assertSame('', $this->sql->fetchOne('SELECT content FROM semantic_memory_excerpt WHERE id = ?', [$id]));
        self::assertFileDoesNotExist($this->indexPath());
    }

    public function testOrphanSqlErasureRollsBackAndSweepNeverPurgesAnActiveTransaction(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('new');
        $this->memory->sweep();
        $before = $this->registry->preference('alice');
        $source = $this->sql->fetchAssociative('SELECT * FROM semantic_memory_excerpt WHERE id = ?', [$id]);
        $this->sql->delete('account', ['id' => 'alice']);
        $this->sql->beginTransaction();
        self::assertTrue($this->registry->eraseOrphan('alice'));
        try {
            $this->memory->sweep();
            self::fail('Sweep must not purge files before the SQL erasure commits');
        } catch (RuntimeException $error) {
            self::assertSame('Semantic sweep must run after commit', $error->getMessage());
        } finally {
            $this->sql->rollBack();
        }
        self::assertSame($before, $this->registry->preference('alice'));
        self::assertSame($source, $this->sql->fetchAssociative(
            'SELECT * FROM semantic_memory_excerpt WHERE id = ?', [$id],
        ));
        self::assertFileExists($this->indexPath());
    }

    public function testPendingBoundsCandidatesBeforeAnyAuthorizationJoins(): void
    {
        $this->registry->setEnabled('alice', true);
        for ($i = 1; $i <= 24; ++$i) {
            $id = $this->turn('bounded-' . $i);
            $this->sql->update('semantic_memory_excerpt', ['updated_at' => $i], ['id' => $id]);
        }
        $this->sqlLog->queries = [];
        $eligible = $this->registry->pending(3);
        self::assertCount(3, $eligible);
        self::assertSame(
            "SELECT id, user_id, updated_at FROM semantic_memory_excerpt WHERE status = 'pending'"
            . ' ORDER BY updated_at, id LIMIT 3', $this->sqlLog->queries[0],
        );
        self::assertCount(3, array_filter($this->sqlLog->queries, static fn (string $query): bool =>
            str_starts_with($query, 'SELECT e.* FROM semantic_memory_excerpt e')));
        self::assertSame(3, (int) $this->sql->fetchOne(
            'SELECT COUNT(*) FROM semantic_memory_excerpt WHERE updated_at > 24',
        ));
        self::assertSame(['bounded-1', 'bounded-2', 'bounded-3'], array_column($eligible, 'turn_id'));
        self::assertSame(['bounded-4', 'bounded-5', 'bounded-6'], array_column($this->registry->pending(3), 'turn_id'));
        self::assertSame(0, $this->embeddings->calls);
    }

    public function testDisabledAndStaleHeadRowsRotateWithoutLosingEligibleMemory(): void
    {
        $this->registry->setEnabled('bob', true);
        $this->registry->setEnabled('alice', true);
        $this->registry->setEnabled('mallory', true);
        $disabled = $this->turn('disabled', 'bob');
        $stale = $this->turn('stale');
        $valid = $this->turn('valid', 'mallory');
        foreach ([$disabled, $stale, $valid] as $position => $id) {
            $this->sql->update('semantic_memory_excerpt', ['updated_at' => $position + 1], ['id' => $id]);
        }
        $this->registry->setEnabled('bob', false);
        $this->sql->delete('chat_history', ['thread_id' => 'thread-stale']);
        $source = $this->sql->fetchAssociative('SELECT * FROM semantic_memory_excerpt WHERE id = ?', [$disabled]);
        self::assertSame([], $this->registry->pending(1));
        self::assertSame([], $this->registry->pending(1));
        self::assertSame([$valid], array_column($this->registry->pending(1), 'id'));
        $deferred = $this->sql->fetchAssociative('SELECT * FROM semantic_memory_excerpt WHERE id = ?', [$disabled]);
        self::assertGreaterThan((int) $source['updated_at'], (int) $deferred['updated_at']);
        unset($source['updated_at'], $deferred['updated_at']);
        self::assertSame($source, $deferred);
        self::assertSame(0, $this->embeddings->calls);
        $this->registry->setEnabled('bob', true);
        self::assertSame([$disabled], array_column($this->registry->pending(1), 'id'));
        self::assertSame('pending', $this->sql->fetchOne(
            'SELECT status FROM semantic_memory_excerpt WHERE id = ?', [$stale],
        ));
    }

    public function testProviderFailureDoesNotJumpAheadOfLaterPendingSourcesAndRemainsReplayable(): void
    {
        $this->registry->setEnabled('alice', true);
        $failed = $this->turn('failed');
        $later = $this->turn('later');
        $this->sql->update('semantic_memory_excerpt', ['updated_at' => 1], ['id' => $failed]);
        $this->sql->update('semantic_memory_excerpt', ['updated_at' => 2], ['id' => $later]);
        $this->embeddings->duringCall = function (): void {
            self::assertFalse($this->sql->isTransactionActive(), 'Embedding must run strictly after SQL commit');
        };
        $this->embeddings->fail = true;
        $this->memory->sweep(1);
        self::assertSame(1, $this->embeddings->calls);
        self::assertSame('pending', $this->registry->source('alice', $failed)['status']);
        $this->embeddings->fail = false;
        $this->memory->sweep(1);
        self::assertSame(2, $this->embeddings->calls);
        self::assertSame('indexed', $this->registry->source('alice', $later)['status']);
        self::assertSame('pending', $this->registry->source('alice', $failed)['status']);
        $this->memory->sweep(1);
        self::assertSame(3, $this->embeddings->calls);
        self::assertSame('indexed', $this->registry->source('alice', $failed)['status']);
        self::assertSame([], $this->registry->pending());
    }

    public function testPendingClaimCannotOverwriteAConcurrentRotation(): void
    {
        $this->registry->setEnabled('alice', true);
        $first = $this->turn('first');
        $later = $this->turn('later');
        $this->sql->update('semantic_memory_excerpt', ['updated_at' => 1], ['id' => $first]);
        $this->sql->update('semantic_memory_excerpt', ['updated_at' => 2], ['id' => $later]);
        $this->sqlLog->beforeQuery = function (string $query) use ($first): void {
            if (str_starts_with($query, 'UPDATE semantic_memory_excerpt SET updated_at = ?')) {
                $this->sqlLog->beforeQuery = null;
                $this->sql->update('semantic_memory_excerpt', ['updated_at' => 500], ['id' => $first]);
            }
        };
        self::assertSame([], $this->registry->pending(1));
        self::assertSame(500, (int) $this->sql->fetchOne(
            'SELECT updated_at FROM semantic_memory_excerpt WHERE id = ?', [$first],
        ));
        self::assertSame([$later], array_column($this->registry->pending(1), 'id'));
        self::assertSame(0, $this->embeddings->calls);
    }

    public function testSourceLocatorCannotAuthorizeAnExcerptErasedBeforeFinalPointRead(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('new');
        $this->sqlLog->beforeQuery = function (string $query): void {
            if (str_starts_with($query, 'SELECT e.*, a.id AS account_owner')) {
                $this->sqlLog->beforeQuery = null;
                $this->registry->erase('alice');
            }
        };
        self::assertNull($this->registry->source('alice', $id));
        self::assertSame('invalid', $this->sql->fetchOne(
            'SELECT status FROM semantic_memory_excerpt WHERE id = ?', [$id],
        ));
        self::assertSame(0, $this->embeddings->calls);
    }

    public function testDurableOutboxDedupProvenanceAndOtherThreadRecall(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('new');
        $this->sql->transactional(fn () => $this->record('new'));
        self::assertCount(1, $this->registry->pending());
        (new SweepJob($this->memory))->handle([]);
        (new IndexTurnJob($this->memory))->handle(['userId' => 'alice', 'documentId' => $id]);
        self::assertSame(1, $this->embeddings->calls);
        self::assertSame([], $this->registry->pending());
        self::assertSame('', $this->memory->recall('alice', 'thread-new', 'query'));
        self::assertSame('', $this->memory->recall('mallory', 'other', 'query'));
        self::assertSame(1, $this->embeddings->calls);
        self::assertStringContainsString('User: synthetic user', $this->memory->recall('alice', 'other', 'query'));
        $row = json_decode(file_get_contents($this->indexPath()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('conversation', $row['sourceType']);
        self::assertSame('thread-new', $row['sourceName']);
        self::assertSame(['u-new', 'a-new'], $row['metadata']['sourceIds']);
        self::assertSame('synthetic/model-v1', $row['metadata']['model']);
        self::assertSame(3, $row['metadata']['dimensions']);
    }

    public function testTurnMustBeCapturedAndSucceededWithinTransactions(): void
    {
        $this->registry->setEnabled('alice', true);
        foreach (['running', 'stopped', 'rolled_back'] as $status) {
            $this->turn($status, status: $status);
        }
        self::assertSame([], $this->registry->pending());
        $this->expectException(RuntimeException::class);
        $this->registry->captureTurn('alice', 'running');
    }

    public function testOutboxRollsBackWithBusinessCommit(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->turn('new', status: 'running');
        $this->sql->beginTransaction();
        $this->sql->update('chat_turn', ['status' => 'succeeded'], ['id' => 'new']);
        $this->record('new');
        self::assertCount(1, $this->registry->pending());
        $this->sql->rollBack();
        self::assertSame([], $this->registry->pending());
        self::assertSame('running', $this->sql->fetchOne('SELECT status FROM chat_turn WHERE id = ?', ['new']));
    }

    public function testDisableRetainsIndexedDataAndEnableDoesNotIngestOldTurns(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->turn('kept');
        $this->memory->sweep();
        $this->turn('pending');
        $this->registry->setEnabled('alice', false);
        $this->turn('off');
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        self::assertSame(1, $this->embeddings->calls);
        $this->registry->setEnabled('alice', true);
        $this->memory->sweep();
        self::assertSame(2, $this->embeddings->calls);
        self::assertStringContainsString('synthetic user', $this->memory->recall('alice', 'other', 'query'));
    }

    public function testEnableDuringTurnAndDisableEnableCycleCannotGrantConsentRetroactively(): void
    {
        $this->turn('off-start', status: 'running');
        $this->registry->setEnabled('alice', true);
        $this->sql->transactional(function (): void {
            $this->sql->update('chat_turn', ['status' => 'succeeded'], ['id' => 'off-start']);
            $this->record('off-start');
        });
        $this->turn('on-start', status: 'running');
        $this->registry->setEnabled('alice', false);
        $this->registry->setEnabled('alice', true);
        $this->sql->transactional(function (): void {
            $this->sql->update('chat_turn', ['status' => 'succeeded'], ['id' => 'on-start']);
            $this->record('on-start');
        });
        self::assertSame([], $this->registry->pending());
    }

    public function testEraseDuringEmbeddingFencesStaleJobAndPurgesEvenCorruptOldIndex(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->turn('indexed');
        $this->memory->sweep();
        $oldPath = $this->indexPath();
        file_put_contents($oldPath, 'synthetic corrupt index');
        $staging = dirname($oldPath) . '/.semantic-interrupted';
        file_put_contents($staging, 'synthetic staged private excerpt');
        $id = $this->turn('in-flight');
        $this->embeddings->duringCall = fn () => $this->registry->erase('alice');
        self::assertFalse($this->memory->index('alice', $id));
        self::assertSame(2, $this->registry->preference('alice')['indexVersion']);
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        self::assertTrue($this->memory->purge('alice'));
        self::assertFileDoesNotExist($oldPath);
        self::assertFileDoesNotExist($staging);
        self::assertSame([], $this->registry->pendingPurges());
        self::assertFalse($this->memory->index('alice', $id));
        self::assertSame(0, (int) $this->sql->fetchOne("SELECT COUNT(*) FROM semantic_memory_excerpt WHERE content <> ''"));
    }

    public function testDeletionDuringEmbeddingAndDuringRetrievalIsAuthoritativelyFiltered(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('in-flight');
        $this->embeddings->duringCall = fn () => $this->sql->transactional(
            fn () => $this->registry->invalidateSources('alice', 'thread-in-flight', ['u-in-flight']),
        );
        self::assertFalse($this->memory->index('alice', $id));
        $this->embeddings->duringCall = null;
        $this->turn('indexed');
        $this->memory->sweep();
        $this->embeddings->duringCall = fn () => $this->sql->transactional(
            fn () => $this->registry->invalidateSources('alice', 'thread-indexed'),
        );
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        self::assertTrue($this->memory->purge('alice'));
        self::assertSame([], glob($this->directory . '/*/*.store'));
    }

    public function testOrphanFileCannotLeakAfterHistoryDeletionEvenBeforePurge(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->turn('indexed');
        $this->memory->sweep();
        $this->sql->delete('chat_history', ['thread_id' => 'thread-indexed']);
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        self::assertSame(1, $this->embeddings->calls);
    }

    public function testFilePublishedBeforeSqlCommitIsNotVisibleAndRetryDoesNotDuplicate(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('interrupted');
        self::assertTrue($this->memory->index('alice', $id));
        $this->sql->update('semantic_memory_excerpt', [
            'status' => 'pending', 'model' => null, 'dimensions' => null,
        ], ['id' => $id]);
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
        self::assertSame(1, $this->embeddings->calls);
        self::assertTrue($this->memory->index('alice', $id));
        self::assertCount(1, file($this->indexPath(), FILE_IGNORE_NEW_LINES));
    }

    public function testRecallUsesSqlTextNotVectorPayloadAndCorruptSearchFailsOpen(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->turn('new');
        $this->memory->sweep();
        $row = json_decode(file_get_contents($this->indexPath()), true, flags: JSON_THROW_ON_ERROR);
        $row['content'] = 'forged vector text';
        file_put_contents($this->indexPath(), json_encode($row, JSON_THROW_ON_ERROR) . "\n");
        $context = $this->memory->recall('alice', 'other', 'query');
        self::assertStringContainsString('synthetic user', $context);
        self::assertStringNotContainsString('forged', $context);
        $row['embedding'] = [1.0];
        file_put_contents($this->indexPath(), json_encode($row, JSON_THROW_ON_ERROR) . "\n");
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
    }

    public function testEmbeddingFailureAndDimensionChangeAreFailOpenAndReplayable(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('new');
        $this->embeddings->fail = true;
        self::assertFalse($this->memory->index('alice', $id));
        self::assertCount(1, $this->registry->pending());
        $this->embeddings->fail = false;
        $this->embeddings->vector = [1.0];
        self::assertFalse($this->memory->index('alice', $id));
        $this->embeddings->vector = [1.0, 0.0, 0.0];
        self::assertTrue($this->memory->index('alice', $id));
        $calls = $this->embeddings->calls;
        self::assertSame('', $this->service('synthetic/model-v2')->recall('alice', 'other', 'query'));
        self::assertSame($calls, $this->embeddings->calls);
        $this->embeddings->fail = true;
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
    }

    public function testSourceDeletionPreservesOtherExcerptsAndIsolation(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->registry->setEnabled('bob', true);
        $this->turn('first');
        $this->turn('second');
        $this->turn('bob', 'bob');
        $this->memory->sweep();
        $this->sql->transactional(fn () => $this->registry->invalidateSources('alice', 'thread-first', ['u-first']));
        $this->registry->setEnabled('alice', false);
        $this->memory->sweep();
        self::assertFileExists($this->indexPath());
        $this->registry->setEnabled('alice', true);
        $result = $this->memory->recall('alice', 'other', 'query');
        self::assertStringContainsString('thread-second', $result);
        self::assertStringNotContainsString('thread-first', $result);
        self::assertStringNotContainsString('thread-bob', $result);
    }

    public function testNoEmbeddingInsideBusinessTransactionAndNoUncommittedIndex(): void
    {
        $this->registry->setEnabled('alice', true);
        $this->sql->beginTransaction();
        $id = $this->turn('new');
        self::assertFalse($this->memory->index('alice', $id));
        self::assertSame(0, $this->embeddings->calls);
        $this->sql->rollBack();
        self::assertSame([], $this->registry->pending());
    }

    public function testGlobalSwitchFailsClosedButStillAllowsErasureAndPurge(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('indexed');
        $this->memory->sweep();
        $disabled = new SemanticMemoryService($this->sql, $this->registry, null,
            new NullLogger(), $this->directory, '', 0);
        self::assertFalse($disabled->index('alice', $id));
        self::assertSame('', $disabled->recall('alice', 'other', 'query'));
        self::assertSame(1, $this->embeddings->calls);
        $this->registry = new SemanticMemoryRegistry($this->sql);
        $this->turn('globally-disabled');
        self::assertSame(1, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM semantic_memory_excerpt'));
        $this->registry->erase('alice');
        $disabled->sweep();
        self::assertSame([], glob($this->directory . '/*/*.store'));
    }

    public function testExcerptHasConfiguredTotalCharacterBudgetAndReturnsOutboxIdentity(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('bounded', status: 'running');
        $registry = new SemanticMemoryRegistry($this->sql, enabled: true, maxCharacters: 100);
        $result = $this->sql->transactional(function () use ($registry): ?string {
            $this->sql->update('chat_turn', ['status' => 'succeeded'], ['id' => 'bounded']);
            return $registry->recordSucceededTurn('alice', 'bounded', 'u-bounded', 'a-bounded',
                str_repeat('X', 5000), str_repeat('Y', 5000));
        });
        self::assertSame($id, $result);
        self::assertSame(100, mb_strlen($this->registry->source('alice', $id)['content']));
        $this->memory->sweep();
        $context = $this->memory->recall('alice', 'other', 'query', 200);
        self::assertNotSame('', $context);
        self::assertLessThanOrEqual(200, mb_strlen($context));
        self::assertIsArray(json_decode(explode("\n", $context, 2)[1], true, flags: JSON_THROW_ON_ERROR));
        self::assertNull($this->sql->transactional(fn () => $registry->recordSucceededTurn(
            'alice', 'bounded', 'u-bounded', 'a-bounded', 'duplicate', 'duplicate',
        )));
    }

    public function testDisableDuringEmbeddingAndRecallRefusesPublicationAndInjection(): void
    {
        $this->registry->setEnabled('alice', true);
        $id = $this->turn('in-flight');
        $this->embeddings->duringCall = fn () => $this->registry->setEnabled('alice', false);
        self::assertFalse($this->memory->index('alice', $id));
        $this->embeddings->duringCall = null;
        $this->registry->setEnabled('alice', true);
        $this->turn('indexed');
        $this->memory->sweep();
        $this->embeddings->duringCall = fn () => $this->registry->setEnabled('alice', false);
        self::assertSame('', $this->memory->recall('alice', 'other', 'query'));
    }

    public function testConcurrentIndexJobsPreserveBothDocumentsWithAtomicPublication(): void
    {
        if ((getenv('CLAIRE_SEMANTIC_TEST_DRIVER') ?: 'pdo_sqlite') !== 'pdo_sqlite') {
            self::markTestSkipped('Multi-process test uses a disposable file-backed SQLite database');
        }
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for multi-process index mutation validation');
        }
        $this->registry->setEnabled('alice', true);
        $ids = [$this->turn('first'), $this->turn('second')];
        mkdir($this->directory, 0700, true);
        $database = $this->directory . '/synthetic.sqlite';
        $this->sql->executeStatement('VACUUM INTO ?', [$database]);
        $this->sql->close();
        $children = [];
        foreach ($ids as $id) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Cannot fork index test worker');
            }
            if ($pid === 0) {
                $sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database]);
                $sql->executeStatement('PRAGMA busy_timeout = 5000');
                $embeddings = new SyntheticSemanticEmbeddings();
                $embeddings->duringCall = static fn () => usleep(20000);
                $memory = new SemanticMemoryService($sql, new SemanticMemoryRegistry($sql, enabled: true),
                    $embeddings, new NullLogger(), $this->directory, 'synthetic/model-v1', 3, enabled: true);
                exit($memory->index('alice', $id) ? 0 : 1);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        $this->sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $database]);
        $this->registry = new SemanticMemoryRegistry($this->sql, enabled: true);
        $this->memory = $this->service();
        self::assertSame([], $this->registry->pending());
        $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->indexPath(), FILE_IGNORE_NEW_LINES));
        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing($ids, array_column($rows, 'id'));
        self::assertSame([], glob(dirname($this->indexPath()) . '/.semantic-*'));
    }

    private function service(string $model = 'synthetic/model-v1'): SemanticMemoryService
    {
        return new SemanticMemoryService($this->sql, $this->registry, $this->embeddings,
            new NullLogger(), $this->directory, $model, 3, enabled: true);
    }

    private function indexPath(): string
    {
        return $this->directory . '/' . hash('sha256', 'alice') . '/v1-'
            . hash('sha256', 'synthetic/model-v1:3') . '.store';
    }

    private function turn(string $id, string $owner = 'alice', string $status = 'succeeded'): string
    {
        $this->sql->transactional(function () use ($id, $owner, $status): void {
            $this->sql->insert('chat_history', ['user_id' => $owner, 'thread_id' => 'thread-' . $id]);
            $this->sql->insert('chat_turn', [
                'id' => $id, 'user_id' => $owner, 'thread_id' => 'thread-' . $id, 'status' => 'running',
            ]);
            $this->registry->captureTurn($owner, $id);
            $this->registry->captureTurn($owner, $id);
            $this->sql->update('chat_turn', ['status' => $status], ['id' => $id]);
            $this->record($id, $owner);
        });
        return (string) $this->sql->fetchOne('SELECT id FROM semantic_memory_excerpt WHERE turn_id = ?', [$id]);
    }

    private function record(string $id, string $owner = 'alice'): void
    {
        $this->registry->recordSucceededTurn($owner, $id, 'u-' . $id, 'a-' . $id,
            'synthetic user', 'synthetic assistant');
    }
}
