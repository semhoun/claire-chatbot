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

    /**
     * @param callable(string, callable(): void, mixed): string $generate
     * @param callable(string, callable(string, callable(): void): void): void $deliver
     * @param array<string, string|int|null> $notification
     * @param (callable(): mixed)|null $prepare Retryable media preparation, never agent/history entry.
     */
    public function run(
        string $userId, string $threadId, string $generationId, callable $generate, callable $deliver,
        array $notification = [], ?callable $notifyFailure = null, ?callable $prepare = null,
    ): void
    {
        if ($generationId === '') {
            throw new NonRetryableJobException('Stable Telegram generation ID is required');
        }

        $botId = explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0];
        $id = TelegramJournal::id($botId, $generationId);
        $telegramJournal = new TelegramJournal($this->connection);
        $turns = new ChatTurnJournal($this->connection);
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
                $turn = $turns->get($id);
                if (($turn['deletedAt'] ?? null) !== null) {
                    return;
                }
                if ($turn !== null && $turn['status'] !== 'succeeded') {
                    $turns->rollback($id, $userId);
                    $this->project($userId, $threadId, $id, 'error');
                    $this->notifyFailure($id, $notifyFailure);
                    return;
                }
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

                    $prepared = $prepare === null ? null : $prepare();
                    $lock->assertHeld();
                    $record['attempted'] = true;
                    $turns->begin($id, $userId, $threadId, 'telegram', $generationId,
                        notification: $notification,
                        atomic: function () use ($telegramJournal, $id, &$record): void {
                            $telegramJournal->saveInTransaction($id, $record);
                        });
                    $this->project($userId, $threadId, $id, 'running');
                    try {
                        $record['response'] = $generate($threadId, $lock->assertHeld(...), $prepared);
                        $lock->assertHeld();
                        $completed = $turns->succeed($id, $userId,
                            function () use ($telegramJournal, $id, &$record): void {
                                $telegramJournal->saveInTransaction($id, $record);
                            });
                        if ($completed['status'] !== 'succeeded') {
                            throw new \RuntimeException('Telegram turn was invalidated before completion');
                        }
                    } catch (\Throwable $error) {
                        if (($turns->get($id)['status'] ?? '') !== 'succeeded') {
                            $turns->rollback($id, $userId);
                            $this->project($userId, $threadId, $id, 'error');
                            $this->notifyFailure($id, $notifyFailure);
                            throw new NonRetryableJobException('Telegram attempt failed after agent entry', 0, $error);
                        }
                        $record = $telegramJournal->load($id);
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

    /**
     * Caller holds the session lock; no agent is entered when retries are exhausted.
     *
     * @param array<string, string|int|null> $notification
     */
    public function fail(
        string $userId, string $threadId, string $generationId, array $notification, callable $notify,
    ): void {
        $botId = explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0];
        $id = TelegramJournal::id($botId, $generationId);
        $journal = new TelegramJournal($this->connection);
        $turns = new ChatTurnJournal($this->connection);
        $updateLock = new ChatThreadLock($this->connection->getNativeConnection(), 'telegram-journal', $id);
        try {
            $record = $journal->load($id) ?? compact('botId', 'userId', 'threadId') + ['updateId' => $generationId];
            if ($record['userId'] !== $userId || isset($record['response']) || ($record['delivered'] ?? false)) {
                return;
            }
            $lock = new ChatThreadLock($this->connection->getNativeConnection(), $userId, $record['threadId']);
            try {
                $turn = $turns->get($id);
                if ($turn === null) {
                    // Legacy ambiguous attempts have no trustworthy checkpoint.
                    if ($record['attempted'] ?? false) {
                        return;
                    }
                    $state = $this->chatGenerationState->get($userId, $record['threadId']);
                    if (in_array($state['status'] ?? '', ['deleted', 'running', 'queued'], true)
                        && ($state['messageId'] ?? '') !== $id) {
                        return;
                    }
                    $record['attempted'] = true;
                    $turns->begin($id, $userId, $record['threadId'], 'telegram', $generationId,
                        notification: $notification,
                        atomic: function () use ($journal, $id, &$record): void {
                            $journal->saveInTransaction($id, $record);
                        });
                }
                $turn = $turns->rollback($id, $userId);
                if ($turn['status'] === 'rolled_back' && ($turn['deletedAt'] ?? null) === null) {
                    $this->project($userId, $record['threadId'], $id, 'error');
                    $this->notifyFailure($id, $notify);
                }
            } finally {
                $lock->release();
            }
        } finally {
            $updateLock->release();
        }
    }

    /** Caller holds the session and journal locks. Delivery may be duplicated after a crash. */
    public function notifyFailure(string $id, ?callable $notify): void
    {
        if ($notify === null) {
            return;
        }
        $journal = new TelegramJournal($this->connection);
        $record = $journal->load($id);
        if ($record === null || isset($record['response']) || $record['delivered']) {
            return;
        }
        $step = $record['deliveries']['failure-notice'] ?? [];
        if (($step['status'] ?? '') === 'confirmed' || ($step['attempts'] ?? 0) >= 3) {
            return;
        }
        $record['deliveries']['failure-notice'] = [
            'attempts' => ($step['attempts'] ?? 0) + 1, 'status' => 'sending',
        ];
        $journal->save($id, $record);
        try {
            $notify();
        } catch (\Throwable) {
            $record['deliveries']['failure-notice']['status'] = 'uncertain';
            $journal->save($id, $record);
            return;
        }
        $record['deliveries']['failure-notice']['status'] = 'confirmed';
        $record['delivered'] = true;
        $journal->save($id, $record);
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
