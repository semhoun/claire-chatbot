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
        $history = $this->history(true, 3);
        $history->replaceMessages([]);
        self::assertSame([], $history->getMessages());
    }

    public function testMysqlNoOpStillRejectsDeletedOrChangedRow(): void
    {
        $history = $this->history(false, 3);
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
        ]]);
        $update = $this->createStub(PDOStatement::class);
        $update->method('rowCount')->willReturn(0);
        $check = $this->createStub(PDOStatement::class);
        $check->method('fetchColumn')->willReturn($exists ? 1 : false);
        $pdo->expects(self::exactly($queryCount))->method('prepare')->willReturnCallback(
            static function (string $sql) use ($load, $update, $check): PDOStatement {
                self::assertStringNotContainsString('xmin', $sql);
                self::assertStringNotContainsString('RETURNING', $sql);
                if (str_starts_with($sql, 'SELECT messages')) {
                    return $load;
                }
                self::assertStringContainsString('CAST(messages AS BINARY) = CAST(:loaded_messages AS BINARY)', $sql);
                self::assertStringContainsString(
                    'CAST(display_messages AS BINARY) = CAST(:loaded_display_messages AS BINARY)', $sql
                );
                if (str_starts_with($sql, 'UPDATE')) {
                    return $update;
                }
                self::assertStringEndsWith(' FOR UPDATE', $sql);
                self::assertStringContainsString('display_messages_count = :display_messages_count', $sql);
                return $check;
            },
        );
        return new UserChatHistory(new InMemorySession([Auth::USERID => 'user']), $pdo, threadId: 'thread');
    }
}
