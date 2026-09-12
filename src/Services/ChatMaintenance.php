<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Queue\QueueRedisConnection;
use Doctrine\DBAL\Connection;

final readonly class ChatMaintenance
{
    // Scan all retained job hashes, not just ready queues: delayed, leased and dead count too.
    private const string PROOF = <<<'LUA'
        local cursor, scanned = '0', 0
        local jobs = {}
        repeat
            local page = redis.call('SCAN', cursor, 'MATCH', ARGV[1] .. 'queue:*', 'COUNT', 100)
            cursor = page[1]
            scanned = scanned + 1
            for _, key in ipairs(page[2]) do
                scanned = scanned + 1
                if scanned > tonumber(ARGV[2]) then return cjson.encode({result='incomplete'}) end
                local kind = redis.call('TYPE', key).ok
                if kind == 'list' or kind == 'zset' then
                    local size = redis.call(kind == 'list' and 'LLEN' or 'ZCARD', key)
                    scanned = scanned + size
                    if scanned > tonumber(ARGV[2]) then return cjson.encode({result='incomplete'}) end
                    local ids = redis.call(kind == 'list' and 'LRANGE' or 'ZRANGE', key, 0, -1)
                    for _, id in ipairs(ids) do
                        if redis.call('TYPE', ARGV[7] .. 'queue:job:' .. id).ok ~= 'hash' then
                            return cjson.encode({result='ambiguous'})
                        end
                    end
                elseif kind ~= 'hash' or string.sub(key, 1, #ARGV[7] + 10) ~= ARGV[7] .. 'queue:job:' then
                    return cjson.encode({result='ambiguous'})
                else
                    local raw = redis.call('HGET', key, 'payload')
                    local ok, payload = pcall(cjson.decode, raw or '')
                    if not ok or type(payload) ~= 'table' then return cjson.encode({result='ambiguous'}) end
                    local class = redis.call('HGET', key, 'job_class') or ''
                    local telegram = string.find(class, 'Telegram', 1, true) ~= nil
                    if telegram then
                        -- The thread itself may be resolved from SQL at execution time.
                        return cjson.encode({result='telegram-job-retained', jobs={{
                            id=redis.call('HGET', key, 'id'), queue=redis.call('HGET', key, 'queue_name'),
                            state=redis.call('HGET', key, 'state') or 'unknown'}}})
                    elseif class ~= [[App\Job\Web\NewMessageJob]] and class ~= [[App\Job\Web\StartThreadJob]]
                        and class ~= [[App\Job\Web\GenerateAudioJob]] then
                        return cjson.encode({result='ambiguous'})
                    end
                    if ARGV[3] ~= '' then
                        if type(payload.threadId) ~= 'string' or type(payload.session) ~= 'table'
                            or type(payload.session[ARGV[5]]) ~= 'string' then
                            return cjson.encode({result='ambiguous'})
                        end
                        if (payload.threadId == ARGV[4] and payload.session[ARGV[5]] == ARGV[3])
                            or ((redis.call('HGET', KEYS[1], 'jobMessageId') or '') ~= ''
                                and redis.call('HGET', KEYS[1], 'jobMessageId') == redis.call('HGET', KEYS[1], 'messageId')
                                and redis.call('HGET', key, 'id') == redis.call('HGET', KEYS[1], 'jobId')) then
                            table.insert(jobs, {id=redis.call('HGET', key, 'id'),
                                queue=redis.call('HGET', key, 'queue_name'),
                                state=redis.call('HGET', key, 'state') or 'unknown'})
                        end
                    end
                end
            end
            if cursor ~= '0' and scanned >= tonumber(ARGV[2]) then
                return cjson.encode({result='incomplete'})
            end
        until cursor == '0'
        if #jobs > 0 then return cjson.encode({result='jobs-retained', jobs=jobs}) end
        LUA;

    public function __construct(
        private QueueRedisConnection $queueRedisConnection,
        private ChatGenerationState $chatGenerationState,
        private Connection $connection,
        private Settings $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function diagnose(string $user, string $thread, bool $reconcile, bool $apply, int $limit): array
    {
        $this->validateLimit($limit);
        try {
            $chatThreadLock = new ChatThreadLock($this->connection->getNativeConnection(), $user, $thread);
        } catch (ChatGenerationBusyException) {
            return [...$this->chatGenerationState->diagnostic($user, $thread), 'result' => 'locked'];
        }

        try {
            $state = $this->chatGenerationState->diagnostic($user, $thread);
            $journal = $this->connection->fetchOne(
                'SELECT id FROM telegram_generation WHERE delivered = 0 '
                . 'AND id = ? AND user_id = ? AND thread_id = ?',
                [$state['messageId'], $user, $thread],
            );
            // Only active intents can execute again; an absent journal leaves ownership unknown.
            $outbox = $this->connection->fetchAssociative(
                'SELECT o.id, o.status FROM queue_outbox o '
                . 'LEFT JOIN telegram_generation g ON o.event_key = g.id '
                . "WHERE o.status IN ('pending', 'published', 'processing') "
                . 'AND (g.id IS NULL OR g.user_id IS NULL OR g.thread_id IS NULL '
                . 'OR (g.user_id = ? AND g.thread_id = ?)) LIMIT 1',
                [$user, $thread],
            );
            if ($journal !== false || $outbox !== false) {
                return [...$state, 'result' => $journal !== false ? 'sql-journal-retained' : 'sql-outbox-retained',
                    'journalId' => $journal === false ? null : $journal,
                    'outbox' => $outbox === false ? null : $outbox];
            }

            $script = self::PROOF . <<<'LUA'

                if redis.call('HGET', KEYS[1], 'status') ~= 'queued'
                    and redis.call('HGET', KEYS[1], 'status') ~= 'running' then
                    return cjson.encode({result='not-active'})
                end
                local attempted = redis.call('HGET', KEYS[1], 'attempted')
                if (attempted ~= '0' and attempted ~= '1')
                    or (redis.call('HGET', KEYS[1], 'messageId') or '') == '' then
                    return cjson.encode({result='ambiguous'})
                end
                if ARGV[6] == '1' then
                    -- Never reopen the old message.
                    redis.call('HSET', KEYS[1], 'status', 'error', 'attempted', '1')
                    redis.call('PERSIST', KEYS[1])
                    return cjson.encode({result='reconciled', status='error', attempted='1'})
                end
                return cjson.encode({result='orphan'})
                LUA;
            $result = $this->queueRedisConnection->evaluate($script, [
                $this->chatGenerationState->key($user, $thread), addcslashes($this->settings->get('redis.prefix'), '\\*?[]'), $limit,
                $user, $thread, Auth::USERID, $reconcile && $apply ? '1' : '0',
                $this->settings->get('redis.prefix'),
            ], 1);
            return [
                ...$state,
                ...json_decode($result, true, flags: JSON_THROW_ON_ERROR),
            ];
        } finally {
            $chatThreadLock->release();
        }
    }

    /** @return array<string, mixed> */
    public function compact(int $days, int $limit, string $cursor = '0', bool $apply = false): array
    {
        $this->validateLimit($limit);
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            throw new \RuntimeException('Compaction requires an independent short commit');
        }

        if ($days < 1 || $days > 36500) {
            throw new \InvalidArgumentException('Invalid retention or cursor');
        }

        $after = self::sqlCursor($cursor);
        $telegramJournal = new TelegramJournal($this->connection);
        $cutoff = $telegramJournal->now() - $days * 86400;
        $parameters = [$cutoff];
        $sql = 'SELECT id, completed_at, revision FROM telegram_generation '
            . 'WHERE delivered = 1 AND compacted = 0 AND completed_at > 0 AND completed_at <= ?';
        if ($after !== null) {
            $sql .= ' AND (completed_at > ? OR (completed_at = ? AND id > ?))';
            $parameters[] = $after[0];
            $parameters[] = $after[0];
            $parameters[] = $after[1];
        }

        $rows = $this->connection->fetchAllAssociative(
            $sql . ' ORDER BY completed_at, id LIMIT ' . ($limit + 1), $parameters,
        );
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }

        $counts = [];
        $next = '0';
        foreach ($rows as $row) {
            $result = $this->compactRecord($telegramJournal, $row, $cutoff, $apply);
            $counts[$result] = ($counts[$result] ?? 0) + 1;
            $next = 'sql:v1:' . base64_encode(json_encode(
                [(int) $row['completed_at'], $row['id']], JSON_THROW_ON_ERROR,
            ));
        }

        return ['cursor' => $more ? $next : '0', 'counts' => $counts];
    }

    /** @return array{int, string}|null */
    public static function sqlCursor(string $cursor): ?array
    {
        if ($cursor === '0') {
            return null;
        }

        try {
            $raw = str_starts_with($cursor, 'sql:v1:') ? base64_decode(substr($cursor, 7), true) : false;
            $value = $raw === false ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $value = null;
        }

        if (! is_array($value) || ! array_is_list($value) || count($value) !== 2
            || ! is_int($value[0]) || $value[0] <= 0
            || ! is_string($value[1]) || preg_match('/^[a-f0-9]{64}$/D', $value[1]) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL cursor; Redis SCAN cursors are not accepted');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function compactRecord(TelegramJournal $telegramJournal, array $row, int $cutoff, bool $apply): string
    {
        $id = $row['id'];
        try {
            $chatThreadLock = new ChatThreadLock($this->connection->getNativeConnection(), 'telegram-journal', $id);
        } catch (ChatGenerationBusyException) {
            return 'locked';
        }

        try {
            $record = $telegramJournal->load($id);
            if ($record === null || $record['_revision'] !== (int) $row['revision']) {
                return 'changed';
            }

            if (($record['attempted'] ?? false) !== true || ($record['delivered'] ?? false) !== true
                || ($record['compacted'] ?? false) !== false) {
                return 'ambiguous';
            }

            foreach (['userId', 'threadId', 'botId', 'updateId'] as $field) {
                if (! is_string($record[$field] ?? null) || $record[$field] === '') {
                    return 'invalid-identity';
                }
            }

            if ($id !== TelegramJournal::id($record['botId'], $record['updateId'])) {
                return 'invalid-identity';
            }

            if (! is_int($record['completedAt'] ?? null) || $record['completedAt'] <= 0
                || $record['completedAt'] > $cutoff) {
                return 'unsafe-date';
            }

            if (! is_array($record['deliveries'] ?? [])) {
                return 'ambiguous';
            }

            foreach ($record['deliveries'] ?? [] as $step) {
                if (! is_array($step) || ($step['status'] ?? null) !== 'confirmed') {
                    return 'ambiguous';
                }
            }

            if (! $apply) {
                return 'eligible';
            }

            // Permanent replay barrier; do not delete even if the outbox still contains this event.
            $changed = $this->connection->executeStatement(
                'UPDATE telegram_generation SET compacted = 1, response = NULL, deliveries = NULL, '
                . 'updated_at = ?, revision = revision + 1 WHERE id = ? AND revision = ? '
                . 'AND attempted = 1 AND delivered = 1 AND compacted = 0 AND completed_at = ? '
                . 'AND completed_at > 0 AND completed_at <= ?',
                [$telegramJournal->now(), $id, $record['_revision'], $record['completedAt'], $cutoff],
            );
            return $changed === 1 ? 'compacted' : 'changed';
        } finally {
            $chatThreadLock->release();
        }
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 10000');
        }
    }
}
