<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Queue\NonRetryableJobException;
use App\Services\Queue\QueueRedisConnection;
use Doctrine\DBAL\Connection;

/** Durable per-update response journal. No TTL: ambiguous tool attempts must never become replayable. */
final readonly class TelegramGeneration
{
    public function __construct(
        private QueueRedisConnection $queueRedisConnection,
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

        $id = hash('sha256', json_encode([
            explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0], $generationId,
        ], JSON_THROW_ON_ERROR));
        $key = $this->settings->get('redis.prefix') . 'telegram:generation:' . $id;
        $updateLock = new ChatThreadLock($this->connection->getNativeConnection(), $userId, 'telegram-update:' . $id);
        try {
            $encoded = $this->queueRedisConnection->evaluate("return redis.call('GET', KEYS[1]) or ''", [$key], 1);
            $record = is_string($encoded) && $encoded !== '' ? json_decode($encoded, true, flags: JSON_THROW_ON_ERROR) : [
                'threadId' => $threadId, 'attempted' => false,
            ];
            $threadId = $record['threadId'];
            if ($record['delivered'] ?? false) {
                return;
            }

            $this->save($key, $record);
            $lock = new ChatThreadLock($this->connection->getNativeConnection(), $userId, $threadId);
            try {
                $previous = $this->chatGenerationState->get($userId, $threadId);
                if (($previous['status'] ?? '') === 'deleted') {
                    throw new NonRetryableJobException('Telegram thread was deleted');
                }

                if (! array_key_exists('response', $record)) {
                    if ($record['attempted']) {
                        if (($previous['messageId'] ?? '') === $id) {
                            $this->chatGenerationState->set($userId, $threadId, $id, 'error', true);
                        }

                        throw new NonRetryableJobException('Ambiguous Telegram agent attempt; tools must not be replayed');
                    }

                    if (in_array($previous['status'] ?? '', ['queued', 'running'], true)) {
                        throw new ChatGenerationBusyException('Chat generation is busy');
                    }

                    $record['attempted'] = true;
                    // A single Lua persists both fences before any agent/history access.
                    $this->save($key, $record, $userId, $threadId, $id, 'running');
                    try {
                        $record['response'] = $generate($threadId);
                        $this->save($key, $record, $userId, $threadId, $id, 'done');
                    } catch (\Throwable $error) {
                        $this->chatGenerationState->set($userId, $threadId, $id, 'error', true);
                        throw new NonRetryableJobException('Telegram attempt failed after agent entry', 0, $error);
                    }
                }
            } finally {
                $lock->release();
            }

            $checkpoint = function (string $step, callable $operation) use ($key, &$record): void {
                if (($record['deliveries'][$step]['status'] ?? '') === 'confirmed') {
                    return;
                }

                $record['deliveries'][$step]['attempts'] = ($record['deliveries'][$step]['attempts'] ?? 0) + 1;
                $record['deliveries'][$step]['status'] = 'sending';
                $this->save($key, $record);
                try {
                    $operation();
                } catch (\Throwable $throwable) {
                    // A transport failure does not prove that Telegram rejected the send.
                    $record['deliveries'][$step]['status'] = 'uncertain';
                    $record['deliveries'][$step]['lastError'] = $throwable::class . ': ' . $throwable->getMessage();
                    $this->save($key, $record);
                    throw $throwable;
                }

                $record['deliveries'][$step]['status'] = 'confirmed';
                $this->save($key, $record);
            };
            $deliver($record['response'], $checkpoint);
            $record['delivered'] = true;
            $this->save($key, $record);
        } finally {
            $updateLock->release();
        }
    }

    /** @param array<string, mixed> $record */
    private function save(
        string $key,
        array $record,
        string $userId = '',
        string $threadId = '',
        string $messageId = '',
        string $status = '',
    ): void {
        $script = <<<'LUA'
            local journalType = redis.call('TYPE', KEYS[1]).ok
            if journalType ~= 'none' and journalType ~= 'string' then
                return redis.error_reply('Invalid Telegram journal type')
            end
            if ARGV[2] ~= '' then
                local stateType = redis.call('TYPE', KEYS[2]).ok
                if stateType ~= 'none' and stateType ~= 'hash' then
                    return redis.error_reply('Invalid generation state type')
                end
                redis.call('HSET', KEYS[2], 'messageId', ARGV[2], 'status', ARGV[3], 'attempted', '1')
            end
            redis.call('SET', KEYS[1], ARGV[1])
            return 1
            LUA;
        $this->queueRedisConnection->evaluate($script, [
            $key, $this->chatGenerationState->key($userId, $threadId),
            json_encode($record, JSON_THROW_ON_ERROR), $messageId, $status,
        ], 2);
    }
}
