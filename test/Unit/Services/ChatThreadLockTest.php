<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatThreadLock;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class ChatThreadLockTest extends TestCase
{
    public function testMysqlLockUsesBoundedNameAndMatchingRelease(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $user = str_repeat('user', 40);
        $thread = str_repeat('thread', 40);
        $key = hash('sha256', 'claire:chat:' . json_encode([$user, $thread], JSON_THROW_ON_ERROR));
        self::assertSame(64, strlen($key));
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('1');
        $statement->expects(self::exactly(2))->method('execute')->with(['key' => $key]);
        $queries = [];
        $pdo->expects(self::exactly(2))->method('prepare')->willReturnCallback(
            static function (string $sql) use (&$queries, $statement): PDOStatement {
                $queries[] = $sql;
                return $statement;
            },
        );
        $lock = new ChatThreadLock($pdo, $user, $thread);
        $lock->release();
        $lock->release();
        self::assertSame(['SELECT GET_LOCK(:key, 0)', 'SELECT RELEASE_LOCK(:key)'], $queries);
    }

    public function testMysqlBusyOrFailedAcquisitionNeverReleasesAnotherConnectionLock(): void
    {
        foreach ([0, null] as $result) {
            $pdo = $this->createMock(PDO::class);
            $pdo->method('getAttribute')->willReturn('mysql');
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetchColumn')->willReturn($result);
            $statement->expects(self::once())->method('execute');
            $pdo->expects(self::once())->method('prepare')->with('SELECT GET_LOCK(:key, 0)')
                ->willReturn($statement);
            try {
                new ChatThreadLock($pdo, 'user', 'thread');
                self::fail('Failed lock acquisition accepted');
            } catch (\RuntimeException $exception) {
                self::assertSame('Chat thread is busy', $exception->getMessage());
            }
        }
    }

    public function testIndependentConnectionsSerializeSameUserThreadButNotOtherUsers(): void
    {
        $first = new ChatThreadLock(new PDO('sqlite::memory:'), 'lock-test-user', 'thread');
        $other = new ChatThreadLock(new PDO('sqlite::memory:'), 'another-user', 'thread');
        try {
            new ChatThreadLock(new PDO('sqlite::memory:'), 'lock-test-user', 'thread');
            self::fail('Concurrent lock accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('Chat thread is busy', $exception->getMessage());
        }
        $first->release();
        $next = new ChatThreadLock(new PDO('sqlite::memory:'), 'lock-test-user', 'thread');
        $next->release();
        $other->release();
    }

    public function testPostgresLockIsUserThreadScopedAndReleasedOnce(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn('pgsql');
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(true);
        $statement->expects(self::exactly(2))->method('execute')->with(['key' => '["user","thread"]']);
        $pdo->expects(self::exactly(2))->method('prepare')->willReturn($statement);
        $lock = new ChatThreadLock($pdo, 'user', 'thread');
        $lock->release();
        $lock->release();
    }

    public function testBusyPostgresLockFailsWithoutUnlockingAnotherWorker(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn('pgsql');
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(false);
        $statement->expects(self::once())->method('execute');
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $this->expectExceptionMessage('Chat thread is busy');
        new ChatThreadLock($pdo, 'user', 'thread');
    }
}
