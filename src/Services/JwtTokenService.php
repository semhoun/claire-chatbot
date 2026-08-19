<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Session\SessionInterface;
use App\Services\Session\SessionManagerInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\JwtFacade;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class JwtTokenService
{
    private const string AUDIENCE_CLAIM = 'aud';

    private const string SESSION_AUDIENCE = 'session';

    private const string MINI_AUDIENCE = 'minitoken';

    private const string USER_ID_CLAIM = 'sub';

    private const string SESSION_DATA_CLAIM = 'data';

    public function __construct(
        private Settings $settings,
    ) {
    }

    public function generateSessionToken(SessionManagerInterface $sessionManager, ?int $lifetime = null): string
    {
        $sessionData = $sessionManager->getStorageAsArray();

        $sessionId = $sessionManager->getId();

        $ttl = $lifetime ?? $this->ttl();

        return $this->buildToken(
            $sessionId,
            [self::SESSION_DATA_CLAIM => $sessionData],
            $ttl,
            self::SESSION_AUDIENCE,
        );
    }

    /**
     * @return array{sessionId:mixed,sessionData:mixed,issuedAt:mixed,expiresAt:mixed}|null
     */
    public function parseSessionToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $parsedToken = $this->parse($token);
            if (! $this->hasAudience($parsedToken, self::SESSION_AUDIENCE)) {
                return null;
            }

            return [
                'sessionId' => $parsedToken->claims()->get(self::USER_ID_CLAIM),
                'sessionData' => $parsedToken->claims()->get(self::SESSION_DATA_CLAIM),
                'issuedAt' => $parsedToken->claims()->get('iat'),
                'expiresAt' => $parsedToken->claims()->get('exp'),
            ];
        } catch (InvalidArgumentException|RuntimeException) {
            return null;
        }
    }

    public function generateMiniToken(SessionInterface $session, ?int $lifetime = null): string
    {
        $userId = $this->extractUserIdFromSession($session);
        if ($userId === '') {
            throw new RuntimeException('Cannot generate mini token without user id');
        }

        $ttl = $lifetime ?? $this->ttl();

        return $this->buildToken($userId, [], $ttl, self::MINI_AUDIENCE);
    }

    public function extractMiniUserId(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        try {
            $parsedToken = $this->parse($token);
            if (! $this->hasAudience($parsedToken, self::MINI_AUDIENCE)) {
                return null;
            }

            $userId = $parsedToken->claims()->get(self::USER_ID_CLAIM);
            if (! is_string($userId) || $userId === '' || $userId === '0') {
                return null;
            }

            return $userId;
        } catch (InvalidArgumentException|RuntimeException) {
            return null;
        }
    }

    public function ttl(): int
    {
        return (int) $this->settings->get('session.lifetime');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function buildToken(
        string $subject,
        array $claims,
        int $lifetime,
        string $audience,
    ): string {
        $inMemory = InMemory::plainText($this->settings->get('session.jwt.secret'));
        $sha256 = new Sha256();
        $now = new DateTimeImmutable();

        $builder = Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify(sprintf('+%d seconds', $lifetime)))
            ->identifiedBy(Uuid::uuid4()->toString())
            ->relatedTo($subject)
            ->permittedFor($audience);

        foreach ($claims as $key => $value) {
            $builder = $builder->withClaim($key, $value);
        }

        return $builder->getToken($sha256, $inMemory)->toString();
    }

    private function parse(string $token): mixed
    {
        $inMemory = InMemory::plainText($this->settings->get('session.jwt.secret'));
        $sha256 = new Sha256();

        return new JwtFacade()->parse(
            $token,
            new SignedWith($sha256, $inMemory),
            new StrictValidAt($this->clock()),
        );
    }

    private function hasAudience(mixed $token, string $expectedAudience): bool
    {
        if (! $token->claims()->has(self::AUDIENCE_CLAIM)) {
            return false;
        }

        $audience = $token->claims()->get(self::AUDIENCE_CLAIM);
        if (is_string($audience)) {
            return $audience === $expectedAudience;
        }

        if (! is_iterable($audience)) {
            return false;
        }

        foreach ($audience as $value) {
            if (is_string($value) && $value === $expectedAudience) {
                return true;
            }
        }

        return false;
    }

    private function extractUserIdFromSession(SessionInterface $session): string
    {
        return (string) $session->get(Auth::USERID, '');
    }

    private function clock(): ClockInterface
    {
        return new class() implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
    }
}
