<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TelegramAction;
use Closure;
use Phptg\BotApi\TelegramBotApi;
use Psr\Log\LoggerInterface as Logger;

final readonly class TelegramChatActionHeartbeat
{
    private const float INTERVAL_SECONDS = 4.0;

    /** @var Closure(int, TelegramAction): void */
    private Closure $sendAction;

    /**
     * @param callable(int, TelegramAction): void|null $sendAction
     */
    public function __construct(
        Settings $settings,
        private Logger $logger,
        ?callable $sendAction = null,
        private float $intervalSeconds = self::INTERVAL_SECONDS,
    ) {
        $token = (string) $settings->get('telegram.bot_token');
        $this->sendAction = $sendAction === null
            ? static function (int $chatId, TelegramAction $telegramAction) use ($token): void {
                new TelegramBotApi($token)->sendChatAction(
                    $chatId,
                    $telegramAction->value,
                );
            }
            : Closure::fromCallable($sendAction);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(
        int $chatId,
        TelegramAction $telegramAction,
        callable $operation,
    ): mixed {
        $heartbeatPid = $this->start($chatId, $telegramAction);

        try {
            return $operation();
        } finally {
            $this->stop($heartbeatPid);
        }
    }

    private function start(
        int $chatId,
        TelegramAction $telegramAction,
    ): ?int
    {
        if (! $this->isSupported()) {
            return null;
        }

        $heartbeatPid = pcntl_fork();
        if ($heartbeatPid === -1) {
            $this->logger->warning('Unable to start Telegram chat action heartbeat');

            return null;
        }

        if ($heartbeatPid > 0) {
            return $heartbeatPid;
        }

        $this->runChild($chatId, $telegramAction, posix_getppid());
    }

    private function runChild(
        int $chatId,
        TelegramAction $telegramAction,
        int $parentPid,
    ): never {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);

        $intervalMicroseconds = max(
            10_000,
            (int) ($this->intervalSeconds * 1_000_000),
        );

        while (posix_getppid() === $parentPid) {
            usleep($intervalMicroseconds);
            if (posix_getppid() !== $parentPid) {
                break;
            }

            try {
                ($this->sendAction)($chatId, $telegramAction);
            } catch (\Throwable) {
                // A later heartbeat may succeed if Telegram is temporarily unavailable.
                continue;
            }
        }

        exit(0);
    }

    private function stop(?int $heartbeatPid): void
    {
        if ($heartbeatPid === null) {
            return;
        }

        posix_kill($heartbeatPid, SIGTERM);
        pcntl_waitpid($heartbeatPid, $status);
    }

    private function isSupported(): bool
    {
        return PHP_SAPI === 'cli'
            && function_exists('pcntl_fork')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_getppid')
            && function_exists('posix_kill');
    }
}
