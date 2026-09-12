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

    public const int RESOURCE_TTL = 300;

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
        } catch (\Throwable) {
            return null;
        }
    }

    /** @deprecated Legacy bootstrap callers only; these tokens no longer authenticate any route. */
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

    public function generateFileToken(SessionInterface $session, string $fileId): string
    {
        return $this->generateResourceToken($session, 'resource-file', ['fileId' => $fileId]);
    }

    public function generateStreamToken(SessionInterface $session, string $threadId, string $sessionId): string
    {
        return $this->generateResourceToken($session, 'resource-stream', [
            'threadId' => $threadId, 'sessionId' => $sessionId,
        ]);
    }

    /** @return array{userId:string,fileId:string,expiresAt:int}|null */
    public function parseFileToken(string $token): ?array
    {
        return $this->parseResourceToken($token, 'resource-file', ['fileId']);
    }

    /** @return array{userId:string,threadId:string,sessionId:string,expiresAt:int}|null */
    public function parseStreamToken(string $token): ?array
    {
        return $this->parseResourceToken($token, 'resource-stream', ['threadId', 'sessionId']);
    }

    /** @param array<string,string> $scope */
    private function generateResourceToken(SessionInterface $session, string $audience, array $scope): string
    {
        $userId = $this->extractUserIdFromSession($session);
        if ($session->get(Auth::AUTHENTICATED) !== true || $userId === '' || $userId === '0') {
            throw new RuntimeException('Cannot generate resource token without authenticated user');
        }

        foreach ($scope as $value) {
            if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x20\/\\\\?#]/', $value)) {
                throw new InvalidArgumentException('Invalid resource scope');
            }
        }

        return $this->buildToken($userId, $scope, min(self::RESOURCE_TTL, max(1, $this->ttl())), $audience);
    }

    /** @param list<string> $fields
     *
     * @return array<string,string|int>|null
     */
    private function parseResourceToken(string $token, string $audience, array $fields): ?array
    {
        try {
            $parsed = $this->parse($token);
            if (! $this->hasAudience($parsed, $audience)) {
                return null;
            }

            $claims = $parsed->claims();
            $userId = $claims->get('sub');
            $expires = $claims->get('exp')->getTimestamp();
            if (! is_string($userId) || $userId === '' || $userId === '0'
                || $expires - $claims->get('iat')->getTimestamp() > self::RESOURCE_TTL) {
                return null;
            }

            $result = ['userId' => $userId, 'expiresAt' => $expires];
            foreach ($fields as $field) {
                $value = $claims->get($field);
                if (! is_string($value) || $value === '') {
                    return null;
                }

                $result[$field] = $value;
            }

            return $result;
        } catch (\Throwable) {
            return null;
        }
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

        return $audience === [$expectedAudience];
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
