<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Session\SessionInterface;
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

final readonly class MiniToken
{
    private const string TYPE_CLAIM = 'typ';

    private const string TYPE_VALUE = 'sse_minitoken';

    private const string USER_ID_CLAIM = 'sub';

    public function __construct(
        private Settings $settings,
    ) {
    }

    public function generate(SessionInterface $session, ?int $lifetime = null): string
    {
        $secret = $this->secret();
        $ttl = $lifetime ?? $this->ttl();
        $userId = $this->extractUserId($session);
        if ($userId === '') {
            throw new RuntimeException('Cannot generate mini token without user id');
        }

        $now = new DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $ttl));

        $inMemory = InMemory::plainText($secret);
        $signer = new Sha256();

        $token = Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiresAt)
            ->identifiedBy(Uuid::uuid4()->toString())
            ->relatedTo($userId)
            ->withClaim(self::TYPE_CLAIM, self::TYPE_VALUE)
            ->getToken($signer, $inMemory);

        return $token->toString();
    }

    public function isValid(string $token): bool
    {
        return $this->extractUserIdFromToken($token) !== null;
    }

    public function extractUserIdFromToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        $secret = $this->secret();
        $inMemory = InMemory::plainText($secret);
        $signer = new Sha256();

        try {
            $parsedToken = (new JwtFacade())->parse(
                $token,
                new SignedWith($signer, $inMemory),
                new StrictValidAt($this->clock()),
            );

            $type = $parsedToken->claims()->get(self::TYPE_CLAIM);
            if (! is_string($type) || $type !== self::TYPE_VALUE) {
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

    private function extractUserId(SessionInterface $session): string
    {
        return (string) $session->get(Auth::USERID, '');
    }

    private function secret(): string
    {
        $secret = (string) $this->settings->get('session.jwt.secret');

        if ($secret === '') {
            throw new RuntimeException('JWT secret key is not configured');
        }

        return $secret;
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
