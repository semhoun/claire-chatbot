<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

use Psr\Http\Message\ServerRequestInterface as Request;

use App\Services\Session\SessionInterface;

final readonly class RememberSession
{
    public const string COOKIE = '__Host-claire-remember';

    public const string ID = 'remember_id';

    public const string EXPIRES = 'remember_expires';

    public const int TTL = 604800;

    public function __construct(private RedisClient $redisClient, private Settings $settings)
    {
    }

    public function issue(SessionInterface $session): string
    {
        $token = bin2hex(random_bytes(32));
        $id = hash('sha256', $token);
        $expires = time() + self::TTL;
        $userId = $session->get(Auth::USERID);
        if ($session->get(Auth::AUTHENTICATED) !== true || ! is_string($userId) || $userId === '') {
            throw new RuntimeException('Cannot remember an unauthenticated session.');
        }
        if (! $this->redisClient->setex($this->key($id), self::TTL, json_encode([
            'user_id' => $userId, 'expires' => $expires,
        ], JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Unable to store remember session.');
        }
        $session->set(self::ID, $id);
        $session->set(self::EXPIRES, $expires);
        return self::COOKIE . '=' . $token . '; Path=/; Max-Age=' . self::TTL
            . '; Secure; HttpOnly; SameSite=Lax';
    }

    public function cookieId(mixed $token): ?string
    {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
            ? hash('sha256', $token) : null;
    }

    /** @return array{user_id:string,expires:int}|null */
    public function find(mixed $id): ?array
    {
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{64}$/D', $id)) {
            return null;
        }
        $value = $this->redisClient->get($this->key($id));
        try {
            $data = $value === false ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! self::validRecord($data, time())) {
            return null;
        }
        return ['user_id' => $data['user_id'], 'expires' => $data['expires']];
    }

    public function valid(SessionInterface $session): bool
    {
        if (! $session->has(self::ID)) {
            return true; // Embed and existing short-lived sessions are not cookie sessions.
        }
        $data = $this->find($session->get(self::ID));
        return $data !== null && $data['user_id'] === $session->get(Auth::USERID)
            && $data['expires'] === $session->get(self::EXPIRES);
    }

    public function revoke(mixed $token): void
    {
        $id = $this->cookieId($token);
        if ($id !== null && $this->redisClient->del($this->key($id)) === false) {
            throw new RuntimeException('Unable to revoke remember session.');
        }
    }

    public function clearCookie(): string
    {
        return self::COOKIE . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax';
    }

    public function allows(Request $request): bool
    {
        $uri = $request->getUri();
        return $request->getMethod() === 'POST'
            && $uri->getScheme() === 'https'
            && $request->getHeaderLine('Origin') === 'https://' . $uri->getAuthority()
            && $request->getHeaderLine('X-Claire-Remember') === '1'
            && in_array($request->getHeaderLine('Sec-Fetch-Site'), ['', 'same-origin'], true);
    }

    private function key(string $id): string
    {
        return self::rememberKey($this->settings->get('redis.prefix'), $id);
    }

    public static function rememberKey(string $prefix, string $id): string
    {
        return $prefix . 'auth:remember:' . $id;
    }

    public static function validRecord(mixed $data, int $now): bool
    {
        return is_array($data) && is_int($data['expires'] ?? null) && $data['expires'] > $now
            && is_string($data['user_id'] ?? null) && $data['user_id'] !== '';
    }
}
