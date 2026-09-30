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
     * @param callable(string, callable(): void, mixed, ?callable): string $generate
     * @param callable(string, callable(string, callable(): void): void, bool, array): void $deliver
     * @param array<string, string|int|null> $notification
     * @param (callable(?callable): mixed)|null $prepare Retryable media preparation, never agent/history entry.
     * @param (callable(string, mixed): void)|null $preserveStoppedInput User text only, never inference/transcription.
     * @param (callable(Connection, string): void)|null $onBegin DB-only, in the turn's initial transaction.
     * @param (callable(Connection, string, string): ?string)|null $onComplete DB-only; optional replacement response.
     */
    public function run(
        string $userId,
        string $threadId,
        string $generationId,
        callable $generate,
        callable $deliver,
        array $notification = [],
        ?callable $notifyFailure = null,
        ?callable $prepare = null,
        ?callable $preserveStoppedInput = null,
        ?callable $onBegin = null,
        ?callable $onComplete = null,
    ): void {
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

            $target = $this->acceptedTarget($userId, $botId, $generationId, $id);
            if ($target !== null) {
                if (isset($record['_revision']) && $record['threadId'] !== $target['thread_id']) {
                    throw new NonRetryableJobException('Telegram acceptance thread mismatch');
                }
                if (isset($notification['chatId']) && (string) $notification['chatId'] !== $target['chat_id']) {
                    throw new NonRetryableJobException('Telegram acceptance destination mismatch');
                }
                if (isset($notification['messageThreadId'])
                    && (int) $notification['messageThreadId'] !== (int) $target['topic_id']) {
                    throw new NonRetryableJobException('Telegram acceptance topic mismatch');
                }
                $record['threadId'] = $target['thread_id'];
                $notification['chatId'] = $target['chat_id'];
                $notification['messageThreadId'] = (int) $target['topic_id'];
            }
            $stops = new ChatStopRequests($this->connection);
            $turns = new ChatTurnJournal($this->connection, $target === null ? null : $stops);
            $stopRequested = $target === null ? null
                : fn (): bool => $stops->isRequested($userId, $record['threadId'], 'telegram', $id);

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
                if ($turn !== null) {
                    $notification = $turn['notification'];
                }
                if ($turn !== null && ! in_array($turn['status'], ['succeeded', 'stopped'], true)) {
                    $turns->rollback($id, $userId);
                    $this->project($userId, $threadId, $id, 'error');
                    $this->notifyFailure($id, $notifyFailure);
                    return;
                }
                if (($turn['status'] ?? null) === 'stopped') {
                    $record['stopped'] = true;
                }
                $cancelBeforePreparation = $stopRequested !== null && $stopRequested();
                try {
                    $previous = $this->chatGenerationState->get($userId, $threadId);
                } catch (\Throwable $error) {
                    if (! array_key_exists('response', $record) && ! $cancelBeforePreparation) {
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
                        if (! $cancelBeforePreparation) {
                            throw new ChatGenerationBusyException('Cannot project Telegram generation', 0, $error);
                        }
                    }

                    try {
                        $prepared = $prepare === null || ($stopRequested !== null && $stopRequested())
                            ? null : $prepare($stopRequested);
                    } catch (\Throwable $error) {
                        if ($stopRequested === null || ! $stopRequested()) {
                            throw $error;
                        }
                        $prepared = null;
                    }
                    $lock->assertHeld();
                    $record['attempted'] = true;
                    $turns->begin(
                        $id,
                        $userId,
                        $threadId,
                        'telegram',
                        $target === null ? $generationId : $id,
                        notification: $notification,
                        atomic: function (Connection $connection) use ($telegramJournal, $id, &$record, $onBegin): void {
                            $telegramJournal->saveInTransaction($id, $record);
                            if ($onBegin !== null) {
                                $onBegin($connection, $id);
                            }
                        }
                    );
                    $this->project($userId, $threadId, $id, 'running');
                    try {
                        if ($stopRequested !== null && $stopRequested()) {
                            if ($preserveStoppedInput !== null) {
                                $preserveStoppedInput($threadId, $prepared);
                            }
                            $record['response'] = '';
                        } else {
                            $record['response'] = $generate($threadId, $lock->assertHeld(...), $prepared, $stopRequested);
                        }
                        $lock->assertHeld();
                        $atomic = function (Connection $connection, string $status = 'succeeded') use (
                            $telegramJournal,
                            $id,
                            &$record,
                            $onComplete,
                        ): void {
                            if ($onComplete !== null) {
                                $response = $onComplete($connection, $id, $status);
                                if (is_string($response)) {
                                    $record['response'] = $response;
                                }
                            }
                            $record['stopped'] = $status === 'stopped';
                            $telegramJournal->saveInTransaction($id, $record);
                        };
                        $completed = $target === null
                            ? $turns->succeed($id, $userId, $atomic)
                            : $turns->complete(
                                $id,
                                $userId,
                                $stopRequested() ? 'stopped' : 'succeeded',
                                $atomic
                            );
                        if (! in_array($completed['status'], ['succeeded', 'stopped'], true)) {
                            throw new \RuntimeException('Telegram turn was invalidated before completion');
                        }
                    } catch (\Throwable $error) {
                        if (! in_array($turns->get($id)['status'] ?? '', ['succeeded', 'stopped'], true)) {
                            $turns->rollback($id, $userId);
                            $this->project($userId, $threadId, $id, 'error');
                            $this->notifyFailure($id, $notifyFailure);
                            throw new NonRetryableJobException('Telegram attempt failed after agent entry', 0, $error);
                        }
                        $record = $telegramJournal->load($id);
                    }
                }

                $this->project($userId, $threadId, $id, $record['stopped'] ?? false ? 'stopped' : 'done');
            } finally {
                $lock->release();
            }

            $checkpoint = function (string $step, callable $operation) use ($id, $telegramJournal, &$record): void {
                if (($record['stopped'] ?? false) && str_starts_with($step, 'voice:')) {
                    return;
                }
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
            $deliver($record['response'], $checkpoint, $record['stopped'] ?? false, $notification);
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
     * @param (callable(array, string): void)|null $notifyStopped Frozen notification and cached response.
     */
    public function fail(
        string $userId,
        string $threadId,
        string $generationId,
        array $notification,
        callable $notify,
        ?callable $preserveStoppedInput = null,
        ?callable $notifyStopped = null,
    ): void {
        $botId = explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0];
        $id = TelegramJournal::id($botId, $generationId);
        $journal = new TelegramJournal($this->connection);
        $target = $this->acceptedTarget($userId, $botId, $generationId, $id);
        $stops = $target === null ? null : new ChatStopRequests($this->connection);
        $turns = new ChatTurnJournal($this->connection, $stops);
        $turn = $turns->get($id);
        $record = $journal->load($id);
        $retryStopped = ($record['userId'] ?? null) === $userId
            && ($record['stopped'] ?? false) && isset($record['response']) && ! $record['delivered'];
        if ($retryStopped || ($target !== null && $turn === null
            && $stops->isRequested($userId, $target['thread_id'], 'telegram', $id))) {
            $this->run(
                $userId,
                $record['threadId'] ?? $target['thread_id'],
                $generationId,
                static function (): never {
                    throw new \LogicException('A stopped queued generation must not enter inference');
                },
                static function (string $response, callable $checkpoint, bool $stopped, array $frozen) use (
                    $notifyStopped,
                    $journal,
                    $id,
                ): void {
                    if ($notifyStopped !== null) {
                        // run() holds the journal lock; use the same bound as failure notices.
                        $step = $journal->load($id)['deliveries']['stopped-notice'] ?? [];
                        if (($step['status'] ?? '') !== 'confirmed' && ($step['attempts'] ?? 0) >= 3) {
                            throw new NonRetryableJobException('Telegram stopped notice delivery exhausted');
                        }
                        $checkpoint('stopped-notice', static fn () => $notifyStopped($frozen, $response));
                    }
                },
                $turn['notification'] ?? $notification,
                preserveStoppedInput: $preserveStoppedInput
            );
            return;
        }
        $updateLock = new ChatThreadLock($this->connection->getNativeConnection(), 'telegram-journal', $id);
        try {
            $threadId = $target['thread_id'] ?? $threadId;
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
                    $turns->begin(
                        $id,
                        $userId,
                        $record['threadId'],
                        'telegram',
                        $target === null ? $generationId : $id,
                        notification: $notification,
                        atomic: function () use ($journal, $id, &$record): void {
                            $journal->saveInTransaction($id, $record);
                        }
                    );
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
        if ($record === null || isset($record['response']) || $record['delivered'] || ($record['stopped'] ?? false)) {
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

    /** @return array<string, mixed>|null */
    private function acceptedTarget(string $userId, string $botId, string $generationId, string $id): ?array
    {
        if (! $this->settings->get('llm.stop.enabled', false) || ! str_starts_with($generationId, 'update:')) {
            return null;
        }
        $target = $this->connection->fetchAssociative('SELECT * FROM telegram_stop_target WHERE id = ?', [$id]);
        if ($target === false) {
            return null;
        }
        if ($target['user_id'] !== $userId || $target['bot_id'] !== $botId
            || (string) $target['update_id'] !== substr($generationId, strlen('update:'))) {
            throw new NonRetryableJobException('Telegram acceptance identity mismatch');
        }
        return $target;
    }
}
