<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatStopRequests;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260930000100;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ChatStopRequestsTest extends TestCase
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

    public function testQueuedRequestIsDurableAndDuplicateRegistrationCannotClearIt(): void
    {
        self::assertSame(['status' => 'accepted', 'stopRequested' => false, 'created' => true],
            $this->stops->register('user', 'thread', 'web', 'generation'));
        $requested = $this->stops->request('user', 'thread', 'web', 'generation');
        self::assertSame(['status' => 'accepted', 'stopRequested' => true], $requested);
        self::assertSame($requested, $this->stops->request('user', 'thread', 'web', 'generation'));
        self::assertSame($requested + ['created' => false],
            $this->stops->register('user', 'thread', 'web', 'generation'));
        self::assertTrue(new ChatStopRequests($this->sql)->isRequested('user', 'thread', 'web', 'generation'));
        self::assertSame(1, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_stop_request'));
    }

    public function testUnknownOwnerThreadChannelAndExactCaseCannotStopAnotherIdentity(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        foreach ([['other', 'thread', 'web', 'generation'], ['user', 'other', 'web', 'generation'],
            ['user', 'thread', 'telegram', 'generation'], ['user', 'thread', 'web', 'Generation'],
            ['user', 'thread', 'web', 'missing']] as $identity) {
            self::assertNull($this->stops->request(...$identity));
        }
        self::assertFalse($this->stops->isRequested('user', 'thread', 'web', 'generation'));
    }

    public function testStopBeforeSuccessWinsAndCannotTargetNextGeneration(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        $this->stops->request('user', 'thread', 'web', 'generation');
        self::assertSame(['status' => 'stopped', 'stopRequested' => true, 'won' => true], $this->finish('succeeded'));
        self::assertSame(['status' => 'stopped', 'stopRequested' => true, 'won' => false], $this->finish('succeeded'));
        $this->stops->register('user', 'thread', 'web', 'next');
        $this->stops->request('user', 'thread', 'web', 'generation');
        self::assertFalse($this->stops->isRequested('user', 'thread', 'web', 'next'));
    }

    public function testSuccessBeforeStopWinsAndTerminalCannotReopen(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        self::assertSame(['status' => 'succeeded', 'stopRequested' => false, 'won' => true], $this->finish('succeeded'));
        self::assertSame(['status' => 'succeeded', 'stopRequested' => false],
            $this->stops->request('user', 'thread', 'web', 'generation'));
        self::assertSame(['status' => 'succeeded', 'stopRequested' => false, 'created' => false],
            $this->stops->register('user', 'thread', 'web', 'generation'));
        self::assertSame(['status' => 'succeeded', 'stopRequested' => false, 'won' => false], $this->finish('stopped'));
    }

    public function testCrashRecoveryRemainsRollbackDespiteStopRequest(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        $this->stops->request('user', 'thread', 'web', 'generation');
        self::assertSame('rolled_back', $this->finish('rolled_back')['status']);
        self::assertFalse($this->finish('succeeded')['won']);
    }

    public function testHistoryFailureRollsBackTerminalButNotPreviouslyCommittedRequest(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        $this->stops->request('user', 'thread', 'web', 'generation');
        $this->sql->executeStatement('CREATE TABLE history_test (status VARCHAR(16))');
        try {
            $this->sql->transactional(function (): void {
                $terminal = $this->stops->arbitrateTerminal('user', 'thread', 'web', 'generation', 'succeeded');
                $this->sql->insert('history_test', ['status' => $terminal['status']]);
                throw new RuntimeException('History fence failed');
            });
            self::fail('History failure expected');
        } catch (RuntimeException $exception) {
            self::assertSame('History fence failed', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM history_test'));
        self::assertSame(['status' => 'accepted', 'stopRequested' => true],
            $this->stops->request('user', 'thread', 'web', 'generation'));
        self::assertTrue($this->finish('succeeded')['won']);
    }

    public function testTerminalRequiresHistoryTransaction(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        $this->expectException(RuntimeException::class);
        $this->stops->arbitrateTerminal('user', 'thread', 'web', 'generation', 'succeeded');
    }

    public function testRequestCannotBeHiddenInsideWorkerTransaction(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        $this->sql->beginTransaction();
        try {
            $this->stops->request('user', 'thread', 'web', 'generation');
            self::fail('Independent commit required');
        } catch (RuntimeException $exception) {
            self::assertSame('Stop requests require an independent short commit', $exception->getMessage());
        } finally {
            $this->sql->rollBack();
        }
        self::assertFalse($this->stops->isRequested('user', 'thread', 'web', 'generation'));
    }

    public function testUnknownWorkerIdentityFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->stops->isRequested('user', 'thread', 'web', 'missing');
    }

    public function testCleanupIsBoundedAndNeverExpiresQueuedOrRequestedWork(): void
    {
        foreach (['generation', 'old', 'queued', 'requested', 'recent'] as $generation) {
            $this->stops->register('user', 'thread', 'web', $generation);
        }
        $this->finish('succeeded');
        $this->finish('rolled_back', 'old');
        $this->stops->request('user', 'thread', 'web', 'requested');
        $this->sql->executeStatement('UPDATE chat_stop_request SET created_at = 1, completed_at = '
            . 'CASE WHEN completed_at IS NOT NULL THEN 2 ELSE NULL END');
        $this->finish('succeeded', 'recent');
        self::assertSame(1, $this->stops->cleanup(time() - 10, 1));
        self::assertSame(1, $this->stops->cleanup(time() - 10, 1));
        self::assertSame(0, $this->stops->cleanup(time() - 10, 1));
        self::assertSame(3, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_stop_request'));
        self::assertTrue($this->stops->isRequested('user', 'thread', 'web', 'requested'));
        self::assertFalse($this->stops->isRequested('user', 'thread', 'web', 'queued'));
    }

    public function testTwoConnectionsSerializeStopAgainstTerminalCommitOrRollback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'claire-stop-test-');
        self::assertNotFalse($path);
        $worker = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $http = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        try {
            $migration = new Version20260930000100($worker, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $worker->executeStatement($query->getStatement());
            }
            $http->executeStatement('PRAGMA busy_timeout = 1');
            $workerStops = new ChatStopRequests($worker);
            $httpStops = new ChatStopRequests($http);
            foreach ([true, false] as $commit) {
                $generation = $commit ? 'commit' : 'rollback';
                $workerStops->register('user', 'thread', 'web', $generation);
                $worker->beginTransaction();
                $workerStops->arbitrateTerminal('user', 'thread', 'web', $generation, 'succeeded');
                try {
                    $httpStops->request('user', 'thread', 'web', $generation);
                    self::fail('The uncommitted terminal must serialize the competing stop');
                } catch (\Doctrine\DBAL\Exception $exception) {
                    self::assertStringContainsString('locked', $exception->getMessage());
                }
                if ($commit) {
                    $worker->commit();
                } else {
                    $worker->rollBack();
                }
                self::assertSame([
                    'status' => $commit ? 'succeeded' : 'accepted', 'stopRequested' => ! $commit,
                ], $httpStops->request('user', 'thread', 'web', $generation));
                $result = $worker->transactional(fn (): array =>
                    $workerStops->arbitrateTerminal('user', 'thread', 'web', $generation, 'succeeded'));
                self::assertSame($commit ? 'succeeded' : 'stopped', $result['status']);
                self::assertSame(! $commit, $result['won']);
            }
        } finally {
            $worker->close();
            $http->close();
            unlink($path);
        }
    }

    public function testDuplicateRegistrationInsideTransactionPreservesCallerWork(): void
    {
        $this->stops->register('user', 'thread', 'web', 'generation');
        $this->sql->transactional(function (): void {
            self::assertFalse($this->stops->register('user', 'thread', 'web', 'generation')['created']);
            self::assertTrue($this->stops->register('user', 'thread', 'telegram', '0123456789')['created']);
        });
        self::assertSame(2, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM chat_stop_request'));
    }

    /** @return array{status: string, stopRequested: bool, won: bool} */
    private function finish(string $status, string $generation = 'generation'): array
    {
        return $this->sql->transactional(fn (): array =>
            $this->stops->arbitrateTerminal('user', 'thread', 'web', $generation, $status));
    }
}
