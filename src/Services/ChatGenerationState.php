<?php

declare(strict_types=1);

namespace App\Services;

/** Mutations must hold ChatThreadLock. No expiry: a crashed attempt must not silently become replayable. */
final readonly class ChatGenerationState
{
    public function __construct(private RedisClient $redisClient, private Settings $settings)
    {
    }

    /** @return array<string, string> */
    public function get(string $userId, string $threadId): array
    {
        $state = $this->redisClient->hgetall($this->key($userId, $threadId));
        if ($state === false) {
            throw new \RuntimeException('Cannot read chat generation state');
        }

        // HSET transitions preserve other fields. Only trust a generation-matched job link.
        if (($state['messageId'] ?? '') === '' || ($state['jobMessageId'] ?? '') !== $state['messageId']) {
            unset($state['jobId'], $state['queue'], $state['jobMessageId']);
        }

        return $state;
    }

    /** @return array<string, string|null> */
    public function diagnostic(string $userId, string $threadId): array
    {
        $state = $this->get($userId, $threadId);
        // Explicit whitelist: never expose response bodies, errors or session fields.
        return [
            'status' => $state['status'] ?? 'missing',
            'attempted' => $state['attempted'] ?? 'unknown',
            'messageId' => $state['messageId'] ?? null,
            'jobId' => $state['jobId'] ?? null,
            'queue' => $state['queue'] ?? null,
        ];
    }

    /** @return array{responding: bool, activeMessageId: ?string} */
    public function snapshot(string $userId, string $threadId): array
    {
        $state = $this->get($userId, $threadId);
        $responding = in_array($state['status'] ?? '', ['queued', 'running'], true);
        return [
            'responding' => $responding,
            'activeMessageId' => $responding ? ($state['messageId'] ?? null) : null,
        ];
    }

    /** @param callable(array<string, string>): array<string, mixed> $readHistory
     *
     * @return array<string, mixed>
     */
    public function capture(string $userId, string $threadId, callable $readHistory): array
    {
        // Do not pair pre-completion messages with a post-completion idle state.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $this->get($userId, $threadId);
            $history = $readHistory($before);
            $after = $this->get($userId, $threadId);
            if ($before === $after) {
                $responding = in_array($after['status'] ?? '', ['queued', 'running'], true);
                return [
                    ...$history,
                    'responding' => $responding,
                    'activeMessageId' => $responding ? ($after['messageId'] ?? null) : null,
                    'generationMessageId' => $after['messageId'] ?? null,
                    'generation' => [
                        'messageId' => $after['messageId'] ?? '',
                        'status' => $after['status'] ?? '',
                    ],
                    'generationStatus' => in_array($after['status'] ?? '', ['queued', 'running', 'done', 'error'], true)
                        ? $after['status'] : null,
                ];
            }
        }

        throw new \RuntimeException('Chat changed while reading its snapshot');
    }

    public function set(string $userId, string $threadId, string $messageId, string $status, bool $attempted): void
    {
        if ($this->redisClient->hset($this->key($userId, $threadId), [
            'messageId' => $messageId,
            'status' => $status,
            'attempted' => $attempted ? '1' : '0',
        ]) === false) {
            throw new \RuntimeException('Cannot persist chat generation state');
        }
    }

    public function acceptsEvent(string $userId, string $threadId, string $event, string $messageId): bool
    {
        return self::acceptsState($this->get($userId, $threadId), $event, $messageId);
    }

    /** @param array<string, string> $state */
    public static function acceptsState(array $state, string $event, string $messageId): bool
    {
        if ($messageId === '' || ($state['messageId'] ?? '') !== $messageId) {
            return false;
        }

        return match ($event) {
            'chat.assistant.done' => ($state['status'] ?? '') === 'done',
            'chat.error' => ($state['status'] ?? '') === 'error',
            'chat.assistant.start', 'chat.assistant.placeholder',
            'chat.assistant.update', 'chat.tool.update' => in_array($state['status'] ?? '', ['queued', 'running'], true),
            default => false,
        };
    }

    public function key(string $userId, string $threadId): string
    {
        return self::stateKey($this->settings->get('redis.prefix'), $userId, $threadId);
    }

    public static function stateKey(string $prefix, string $userId, string $threadId): string
    {
        return $prefix . 'chat:generation:'
            . hash('sha256', json_encode([$userId, $threadId], JSON_THROW_ON_ERROR));
    }
}
