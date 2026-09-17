<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\ChatHistory;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\Session\InMemorySession;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class UserChatHistoryMysqlTest extends TestCase
{
    public function testUnchangedMysqlSnapshotIsNotReportedAsConflict(): void
    {
        $history = $this->history(true, 2);
        $history->replaceMessages([]);
        self::assertSame([], $history->getMessages());
    }

    public function testMysqlNoOpStillRejectsDeletedOrChangedRow(): void
    {
        $history = $this->history(false, 2);
        $this->expectExceptionMessage('refusing stale snapshot');
        $history->replaceMessages([]);
    }

    public function testMysqlChangedSnapshotWithZeroAffectedRowsIsAlwaysConflict(): void
    {
        $history = $this->history(false, 2);
        $this->expectExceptionMessage('refusing stale snapshot');
        $history->addMessage(new UserMessage('New message'));
    }

    private function history(bool $exists, int $queryCount): UserChatHistory
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $load = $this->createStub(PDOStatement::class);
        $load->method('fetchAll')->willReturn([[
            'messages' => '[]', 'display_messages' => '[]', 'title' => null, 'summary' => null,
            'revision' => 0, 'current_turn_id' => null,
        ]]);
        $update = $this->createStub(PDOStatement::class);
        $update->method('rowCount')->willReturn($exists ? 1 : 0);
        $pdo->expects(self::exactly($queryCount))->method('prepare')->willReturnCallback(
            static function (string $sql) use ($load, $update): PDOStatement {
                self::assertStringNotContainsString('xmin', $sql);
                self::assertStringNotContainsString('RETURNING', $sql);
                if (str_starts_with($sql, 'SELECT messages')) {
                    return $load;
                }
                self::assertStringContainsString('CAST(messages AS BINARY) = CAST(:loaded_messages AS BINARY)', $sql);
                self::assertStringContainsString(
                    'CAST(display_messages AS BINARY) = CAST(:loaded_display_messages AS BINARY)', $sql
                );
                self::assertStringContainsString('revision = revision + 1', $sql);
                self::assertStringContainsString('AND revision = :loaded_revision', $sql);
                self::assertStringContainsString('AND current_turn_id IS NULL', $sql);
                return $update;
            },
        );
        return new UserChatHistory(new InMemorySession([Auth::USERID => 'user']), $pdo, threadId: 'thread');
    }
}
