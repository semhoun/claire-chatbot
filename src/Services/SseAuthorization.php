<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Session\ArraySession;
use RuntimeException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ServerRequestFactory;

final readonly class SseAuthorization
{
    private int $duration;

    public function __construct(
        private SseAccess $access,
        private ChatSnapshot $snapshots,
        private RedisClient $redis,
        private Settings $settings,
    ) {
        $duration = $settings->get('sse.duration');
        if (! is_int($duration) || $duration < 1 || $duration > 86400) {
            throw new RuntimeException('Invalid SSE duration.');
        }
        $this->duration = $duration;
    }

    /** @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function open(array $input): array
    {
        $session = $this->access->authenticate($input);
        $openedAt = microtime(true);
        $context = [
            'userId' => $session->get(Auth::USERID),
            'threadId' => $input['threadId'],
            'sessionId' => $input['sessionId'],
            'openedAt' => $openedAt,
            'deadline' => $openedAt + $this->duration,
            'remember' => $session->has(RememberSession::ID) ? [
                'id' => $session->get(RememberSession::ID),
                'expires' => $session->get(RememberSession::EXPIRES),
            ] : null,
        ];
        $authorization = bin2hex(random_bytes(32));
        if (! $this->redis->setex(
            $this->key($authorization),
            $this->duration,
            json_encode($context, JSON_THROW_ON_ERROR)
        )) {
            throw new RuntimeException('Cannot store SSE authorization.');
        }
        if (microtime(true) >= $context['deadline']) {
            $this->close($authorization);
            $this->deny();
        }
        return ['authorization' => $authorization, ...$context];
    }

    /** @return array<string, mixed> */
    public function snapshot(string $authorization): array
    {
        $context = $this->load($authorization);
        $session = new ArraySession();
        $session->start();
        $session->set(Auth::USERID, $context['userId']);
        $session->set(Auth::AUTHENTICATED, true);
        if ($context['remember'] !== null) {
            $session->set(RememberSession::ID, $context['remember']['id']);
            $session->set(RememberSession::EXPIRES, $context['remember']['expires']);
        }
        $this->access->check($session, $context['threadId']);
        $snapshot = $this->snapshots->read($session, $context['threadId']);
        // A slow SQL/render call cannot extend admission or hide revocation during the handshake.
        $this->load($authorization);
        $this->access->check($session, $context['threadId']);
        if (microtime(true) >= $context['deadline']) {
            $this->deny();
        }
        return [...$snapshot, 'threadId' => $context['threadId'], 'sessionId' => $context['sessionId']];
    }

    public function close(string $authorization): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $authorization)) {
            $this->deny();
        }
        if ($this->redis->del($this->key($authorization)) === false) {
            throw new RuntimeException('Cannot delete SSE authorization.');
        }
    }

    /** @return array<string, mixed> */
    private function load(string $authorization): array
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $authorization)) {
            $this->deny();
        }
        $stored = $this->redis->get($this->key($authorization));
        try {
            $context = $stored === false ? null : json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->deny();
        }
        if (! is_array($context) || count($context) !== 6
            || ! is_string($context['userId'] ?? null) || $context['userId'] === ''
            || ! is_string($context['threadId'] ?? null) || $context['threadId'] === ''
            || ! is_string($context['sessionId'] ?? null) || $context['sessionId'] === ''
            || (! is_int($context['openedAt'] ?? null) && ! is_float($context['openedAt'] ?? null))
            || (! is_int($context['deadline'] ?? null) && ! is_float($context['deadline'] ?? null))
            || $context['openedAt'] > microtime(true) || $context['deadline'] <= microtime(true)
            || $context['deadline'] <= $context['openedAt']
            || $context['deadline'] - $context['openedAt'] > 86400
            || ! array_key_exists('remember', $context)) {
            $this->deny();
        }
        if ($context['remember'] !== null
            && (! is_array($context['remember']) || count($context['remember']) !== 2
                || ! is_string($context['remember']['id'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $context['remember']['id'])
                || ! is_int($context['remember']['expires'] ?? null))) {
            $this->deny();
        }
        return $context;
    }

    private function key(string $authorization): string
    {
        return $this->settings->get('redis.prefix') . 'sse:authorization:' . hash('sha256', $authorization);
    }

    private function deny(): never
    {
        throw new HttpUnauthorizedException(new ServerRequestFactory()->createServerRequest('POST', '/snapshot'));
    }
}
