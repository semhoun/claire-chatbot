<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Enums\TelegramAction;
use App\Services\Settings;
use App\Services\TelegramChatActionHeartbeat;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class TelegramChatActionHeartbeatTest extends TestCase
{
    public function testRefreshesActionUntilOperationCompletes(): void
    {
        $heartbeatFile = $this->heartbeatFile();
        $heartbeat = $this->heartbeat($heartbeatFile);

        try {
            $result = $heartbeat->run(
                42,
                TelegramAction::TEXT,
                static function (): string {
                    usleep(60_000);

                    return 'done';
                },
            );

            self::assertSame('done', $result);
            self::assertStringContainsString(
                '42:typing',
                (string) file_get_contents($heartbeatFile),
            );
        } finally {
            unlink($heartbeatFile);
        }
    }

    public function testStopsHeartbeatWhenOperationFails(): void
    {
        $heartbeatFile = $this->heartbeatFile();
        $heartbeat = $this->heartbeat($heartbeatFile);

        try {
            $exceptionCaught = false;
            try {
                $heartbeat->run(
                    42,
                    TelegramAction::TEXT,
                    static function (): never {
                        usleep(30_000);
                        throw new RuntimeException('failure');
                    },
                );
            } catch (RuntimeException) {
                $exceptionCaught = true;
            }

            $sizeAfterFailure = filesize($heartbeatFile);
            self::assertGreaterThan(0, $sizeAfterFailure);
            usleep(30_000);

            self::assertTrue($exceptionCaught);
            self::assertSame($sizeAfterFailure, filesize($heartbeatFile));
        } finally {
            unlink($heartbeatFile);
        }
    }

    private function heartbeat(string $heartbeatFile): TelegramChatActionHeartbeat
    {
        return new TelegramChatActionHeartbeat(
            new Settings(['telegram' => ['bot_token' => 'token']]),
            new NullLogger(),
            static function (
                int $chatId,
                TelegramAction $telegramAction,
            ) use ($heartbeatFile): void {
                file_put_contents(
                    $heartbeatFile,
                    $chatId . ':' . $telegramAction->value . "\n",
                    FILE_APPEND,
                );
            },
            0.01,
        );
    }

    private function heartbeatFile(): string
    {
        $heartbeatFile = tempnam(sys_get_temp_dir(), 'claire-heartbeat-');
        self::assertIsString($heartbeatFile);

        return $heartbeatFile;
    }
}
