<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final readonly class OidcTransaction
{
    public const string COOKIE = '__Host-claire-oidc';

    public const int TTL = 300;

    public function __construct(private RedisClient $redisClient)
    {
    }

    public function issue(string $state): string
    {
        // Independent browser secret: the public OAuth state must never identify the cookie.
        $token = bin2hex(random_bytes(32));
        if ($state === '' || $this->redisClient->hset($this->key($token), [
            'state' => $state,
            'expires' => time() + self::TTL,
        ], self::TTL) === false) {
            throw new RuntimeException('Unable to store OIDC transaction.');
        }

        return self::COOKIE . '=' . $token . '; Path=/; Max-Age=' . self::TTL
            . '; Secure; HttpOnly; SameSite=Lax';
    }

    public function consume(mixed $token): ?string
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        $key = $this->key($token);
        $data = $this->redisClient->hgetall($key);
        // DEL is atomic: concurrent callbacks cannot both consume the same transaction.
        if ($this->redisClient->del($key) !== 1 || ! is_array($data)
            || (int) ($data['expires'] ?? 0) <= time()
            || ! is_string($data['state'] ?? null) || $data['state'] === '') {
            return null;
        }

        return $data['state'];
    }

    public function clearCookie(): string
    {
        return self::COOKIE . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax';
    }

    private function key(string $token): string
    {
        return 'oidc:transaction:' . hash('sha256', $token);
    }
}
