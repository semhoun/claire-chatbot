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
                function () use ($heartbeatFile): string {
                    $this->waitForHeartbeat($heartbeatFile);

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
                    function () use ($heartbeatFile): never {
                        $this->waitForHeartbeat($heartbeatFile);
                        throw new RuntimeException('failure');
                    },
                );
            } catch (RuntimeException) {
                $exceptionCaught = true;
            }

            clearstatcache(true, $heartbeatFile);
            $sizeAfterFailure = filesize($heartbeatFile);
            self::assertGreaterThan(0, $sizeAfterFailure);
            usleep(30_000);

            self::assertTrue($exceptionCaught);
            clearstatcache(true, $heartbeatFile);
            self::assertSame($sizeAfterFailure, filesize($heartbeatFile));
        } finally {
            unlink($heartbeatFile);
        }
    }

    private function waitForHeartbeat(string $heartbeatFile): void
    {
        // Synchronize with the child rather than assuming it is scheduled within 30 ms.
        $deadline = hrtime(true) + 2_000_000_000;
        do {
            clearstatcache(true, $heartbeatFile);
            if (filesize($heartbeatFile) > 0) {
                return;
            }

            usleep(5_000);
        } while (hrtime(true) < $deadline);

        self::fail('The heartbeat child did not write within two seconds');
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
