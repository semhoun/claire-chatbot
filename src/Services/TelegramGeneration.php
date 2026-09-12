<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Queue\NonRetryableJobException;
use Doctrine\DBAL\Connection;

/** Durable per-update response journal. No TTL: ambiguous tool attempts must never become replayable. */
final readonly class TelegramGeneration
{
    public function __construct(
        private Settings $settings,
        private Connection $connection,
        private ChatGenerationState $chatGenerationState,
    ) {
    }

    /** @param callable(string): string $generate
     * @param callable(string, callable(string, callable(): void): void): void $deliver
     */
    public function run(string $userId, string $threadId, string $generationId, callable $generate, callable $deliver): void
    {
        if ($generationId === '') {
            throw new NonRetryableJobException('Stable Telegram generation ID is required');
        }

        $botId = explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0];
        $id = TelegramJournal::id($botId, $generationId);
        $telegramJournal = new TelegramJournal($this->connection);
        $updateLock = new ChatThreadLock($this->connection->getNativeConnection(), 'telegram-journal', $id);
        try {
            $record = $telegramJournal->load($id) ?? [
                'threadId' => $threadId, 'attempted' => false,
                'userId' => $userId, 'updateId' => $generationId,
                'botId' => $botId,
            ];
            if ($record['userId'] !== $userId) {
                throw new NonRetryableJobException('Telegram journal belongs to another user');
            }

            if ($record['delivered'] ?? false) {
                return;
            }

            $telegramJournal->save($id, $record);
            if ($record['compacted']) {
                throw new NonRetryableJobException('Telegram journal is compacted');
            }

            $threadId = $record['threadId'];
            $lock = new ChatThreadLock($this->connection->getNativeConnection(), $userId, $threadId);
            try {
                try {
                    $previous = $this->chatGenerationState->get($userId, $threadId);
                } catch (\Throwable $error) {
                    if (! array_key_exists('response', $record)) {
                        if ($record['attempted']) {
                            throw new NonRetryableJobException('Ambiguous Telegram agent attempt', 0, $error);
                        }

                        throw new ChatGenerationBusyException('Chat state is unavailable', 0, $error);
                    }

                    $previous = [];
                }

                if (($previous['status'] ?? '') === 'deleted') {
                    throw new NonRetryableJobException('Telegram thread was deleted');
                }

                if (! array_key_exists('response', $record)) {
                    if ($record['attempted']) {
                        if (($previous['messageId'] ?? '') === $id) {
                            $this->project($userId, $threadId, $id, 'error');
                        }

                        throw new NonRetryableJobException('Ambiguous Telegram agent attempt; tools must not be replayed');
                    }

                    if (in_array($previous['status'] ?? '', ['queued', 'running'], true)
                        && ($previous['messageId'] ?? '') !== $id) {
                        throw new ChatGenerationBusyException('Chat generation is busy');
                    }

                    // Redis availability is checked before committing the irreversible SQL fence.
                    try {
                        $this->chatGenerationState->set($userId, $threadId, $id, 'running', false);
                    } catch (\Throwable $error) {
                        throw new ChatGenerationBusyException('Cannot project Telegram generation', 0, $error);
                    }

                    $record['attempted'] = true;
                    $telegramJournal->save($id, $record);
                    $this->project($userId, $threadId, $id, 'running');
                    try {
                        $record['response'] = $generate($threadId);
                    } catch (\Throwable $error) {
                        $this->project($userId, $threadId, $id, 'error');
                        throw new NonRetryableJobException('Telegram attempt failed after agent entry', 0, $error);
                    }

                    // A lost commit acknowledgement must be resolved from SQL on retry.
                    try {
                        $telegramJournal->save($id, $record);
                    } catch (\Throwable $error) {
                        $this->project($userId, $threadId, $id, 'error');
                        throw $error;
                    }
                }

                $this->project($userId, $threadId, $id, 'done');
            } finally {
                $lock->release();
            }

            $checkpoint = function (string $step, callable $operation) use ($id, $telegramJournal, &$record): void {
                if (($record['deliveries'][$step]['status'] ?? '') === 'confirmed') {
                    return;
                }

                $record['deliveries'][$step]['attempts'] = ($record['deliveries'][$step]['attempts'] ?? 0) + 1;
                $record['deliveries'][$step]['status'] = 'sending';
                $telegramJournal->save($id, $record);
                try {
                    $operation();
                } catch (\Throwable $throwable) {
                    // A transport failure does not prove that Telegram rejected the send.
                    $record['deliveries'][$step]['status'] = 'uncertain';
                    $record['deliveries'][$step]['lastError'] = $throwable::class . ': ' . $throwable->getMessage();
                    $telegramJournal->save($id, $record);
                    throw $throwable;
                }

                $record['deliveries'][$step]['status'] = 'confirmed';
                $telegramJournal->save($id, $record);
            };
            $deliver($record['response'], $checkpoint);
            $record['delivered'] = true;
            $telegramJournal->save($id, $record);
        } finally {
            $updateLock->release();
        }
    }

    private function project(string $userId, string $threadId, string $id, string $status): void
    {
        try {
            $previous = $this->chatGenerationState->get($userId, $threadId);
            if (($previous['messageId'] ?? '') === $id) {
                $this->chatGenerationState->set($userId, $threadId, $id, $status, true);
            }
        } catch (\Throwable) {
            // A disposable UI projection cannot invalidate an already committed SQL result.
        }
    }
}
