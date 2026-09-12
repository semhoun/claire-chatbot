<?php

declare(strict_types=1);

namespace App\Services\Queue;

final class QueueScripts
{
    public const string DISPATCH = <<<'LUA'
        local function check(key, expected)
            local actual = redis.call('TYPE', key).ok
            return actual == 'none' or actual == expected
        end
        if not check(KEYS[1], 'list') or not check(KEYS[2], 'hash')
            or (ARGV[5] ~= '' and not check(ARGV[5], 'string'))
            or (ARGV[6] ~= '' and not check(ARGV[6], 'hash')) then
            return redis.error_reply('Invalid dispatch key type')
        end
        if ARGV[5] ~= '' then
            local previous = redis.call('GET', ARGV[5])
            if previous then return previous end
        end
        if ARGV[6] ~= '' then
            local status = redis.call('HGET', ARGV[6], 'status')
            local previous = redis.call('HGET', ARGV[6], 'messageId')
            if status == 'queued' or status == 'running' or status == 'deleted'
                or previous == ARGV[7] then return 'CHAT_BUSY' end
            redis.call('HSET', ARGV[6], 'messageId', ARGV[7], 'status', 'queued', 'attempted', '0',
                'jobId', ARGV[1], 'queue', ARGV[2], 'jobMessageId', ARGV[7])
        end
        redis.call('HSET', KEYS[2], 'id', ARGV[1], 'queue_name', ARGV[2],
            'job_class', ARGV[3], 'payload', ARGV[4], 'attempts', 0, 'state', 'ready',
            'deduplication_key', ARGV[5])
        redis.call('LPUSH', KEYS[1], ARGV[1])
        if ARGV[5] ~= '' then redis.call('SET', ARGV[5], ARGV[1]) end
        return ARGV[1]
        LUA;

    // Server time and fencing tokens make transitions independent of worker clocks.
    public const string TRANSITION = <<<'LUA'
        local clock = redis.call('TIME')
        local now = tonumber(clock[1]) + tonumber(clock[2]) / 1000000
        local action, prefix, id, token = ARGV[1], ARGV[2], ARGV[3], ARGV[4]
        local lease, maxAttempts = tonumber(ARGV[5]), tonumber(ARGV[6])
        local function retry(jobId, reason)
            local key = prefix .. jobId
            redis.call('ZREM', KEYS[2], jobId)
            if redis.call('EXISTS', key) == 0 then return end
            local attempts = tonumber(redis.call('HGET', key, 'attempts') or '0')
            redis.call('HDEL', key, 'token')
            redis.call('HSET', key, 'last_error', reason)
            if attempts >= maxAttempts then
                redis.call('HSET', key, 'state', 'dead')
                redis.call('ZADD', KEYS[4], now, jobId)
            else
                local delay = math.min(tonumber(ARGV[8]), tonumber(ARGV[7]) * 2 ^ math.min(30, attempts - 1))
                redis.call('HSET', key, 'state', 'delayed')
                redis.call('ZADD', KEYS[3], now + delay, jobId)
            end
        end
        if action == 'reserve' then
            for _, expired in ipairs(redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', now, 'LIMIT', 0, 100)) do
                retry(expired, 'lease expired')
            end
            for _, ready in ipairs(redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', now, 'LIMIT', 0, 100)) do
                redis.call('ZREM', KEYS[3], ready)
                redis.call('LPUSH', KEYS[1], ready)
            end
            for i = 1, 100 do
                local nextId = redis.call('RPOP', KEYS[1])
                if not nextId then return {} end
                local key = prefix .. nextId
                local state = redis.call('HGET', key, 'state')
                if redis.call('EXISTS', key) == 1 and (not state or state == 'ready' or state == 'delayed') then
                    -- Legacy payloads can carry expireAfter; active jobs must never expire.
                    redis.call('PERSIST', key)
                    redis.call('HINCRBY', key, 'attempts', 1)
                    redis.call('HSET', key, 'state', 'leased', 'token', token)
                    redis.call('ZADD', KEYS[2], now + lease, nextId)
                    return redis.call('HGETALL', key)
                end
            end
            return {}
        end
        local key = prefix .. id
        local expires = tonumber(redis.call('ZSCORE', KEYS[2], id) or '0')
        if token == '' or redis.call('HGET', key, 'token') ~= token or expires <= now then return 0 end
        if action == 'renew' then
            redis.call('ZADD', KEYS[2], now + lease, id)
        elseif action == 'ack' then
            local dedup = redis.call('HGET', key, 'deduplication_key')
            if dedup and dedup ~= '' then redis.call('EXPIRE', dedup, tonumber(ARGV[9])) end
            redis.call('ZREM', KEYS[2], id)
            redis.call('DEL', key)
        elseif action == 'release' then
            retry(id, 'execution failed')
        elseif action == 'fail' then
            redis.call('ZREM', KEYS[2], id)
            redis.call('HDEL', key, 'token')
            redis.call('HSET', key, 'state', 'dead', 'last_error', 'non-retryable execution')
            redis.call('ZADD', KEYS[4], now, id)
        elseif action == 'defer' then
            redis.call('ZREM', KEYS[2], id)
            redis.call('HDEL', key, 'token')
            redis.call('HINCRBY', key, 'attempts', -1)
            redis.call('HSET', key, 'state', 'delayed', 'last_error', 'chat contention')
            redis.call('ZADD', KEYS[3], now + tonumber(ARGV[7]), id)
        else
            return redis.error_reply('Unknown queue transition')
        end
        return 1
        LUA;
}
