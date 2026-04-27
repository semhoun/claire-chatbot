<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Session\ArraySession;
use App\Services\Settings;
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
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Slim\Psr7\NonBufferedBody;

/**
 * Middleware for JWT-based session management via X-Claire-Auth header.
 *
 * Reads JWT from X-Claire-Auth header (or fallback cookie for SSE),
 * populates session data, stores the session in request attributes,
 * and returns the session token in X-Claire-Token response header when
 * the session is modified or refreshed.
 */
final class JwtSessionMiddleware implements MiddlewareInterface
{
    public const string SESSION_ATTRIBUTE = 'session';

    private const string AUTH_HEADER = 'X-Claire-Auth';

    private const string TOKEN_HEADER = 'X-Claire-Token';

    private const string SESSION_TOKEN_COOKIE = 'claire_session_token';

    private const string SESSION_ID_KEY = 'sub';

    private const string SESSION_DATA_KEY = 'data';

    /** @var array<string, mixed> */
    private array $tokenClaims = [];

    /** @var array<string, mixed>|null */
    private ?array $originalSessionData = null;

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $secret = $this->settings->get('session.jwt.secret');
        $tokenString = $this->extractBearerToken($request);

        $this->tokenClaims = [];
        $this->originalSessionData = null;

        // Create a new session instance for this request (not a singleton)
        $arraySession = new ArraySession();

        if ($tokenString !== null) {
            $this->decodeAndPopulateSession($arraySession, $tokenString, $secret);
        } else {
            $arraySession->start();
        }

        // Store session in request attributes
        $request = $request->withAttribute(self::SESSION_ATTRIBUTE, $arraySession);

        $response = $handler->handle($request);

        // Don't modify headers if response is streaming (NonBufferedBody)
        // as output has already started
        if ($response->getBody() instanceof NonBufferedBody) {
            return $response;
        }

        // Return X-Claire-Token header if data changed or token needs refresh
        if ($this->shouldReturnToken($arraySession)) {
            return $this->writeSessionToHeader($arraySession, $response, $secret);
        }

        return $response;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->getHeaderLine(self::AUTH_HEADER);

        if ($authHeader !== '' && $authHeader !== '0') {
            return $authHeader;
        }

        $cookies = $request->getCookieParams();
        $cookieToken = (string) ($cookies[self::SESSION_TOKEN_COOKIE] ?? '');
        if ($cookieToken !== '' && $cookieToken !== '0') {
            return $cookieToken;
        }

        return null;
    }

    private function shouldReturnToken(ArraySession $arraySession): bool
    {
        // If session data changed, return new token
        $currentData = $arraySession->getStorageAsArray();
        if ($this->originalSessionData === null || $currentData !== $this->originalSessionData) {
            return true;
        }

        // Check if token needs refresh (more than 50% through lifetime)
        $issuedAt = $this->tokenClaims['iat'] ?? null;
        $expiresAt = $this->tokenClaims['exp'] ?? null;

        if ($issuedAt instanceof DateTimeImmutable && $expiresAt instanceof DateTimeImmutable) {
            return $this->shouldRefreshToken($issuedAt, $expiresAt);
        }

        return false;
    }

    private function shouldRefreshToken(DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): bool
    {
        $now = new DateTimeImmutable();
        $totalLifetime = $expiresAt->getTimestamp() - $issuedAt->getTimestamp();
        $elapsed = $now->getTimestamp() - $issuedAt->getTimestamp();

        if ($totalLifetime <= 0) {
            return true;
        }

        return $elapsed > $totalLifetime / 2;
    }

    private function decodeAndPopulateSession(ArraySession $arraySession, string $tokenString, string $secret): void
    {
        $inMemory = InMemory::plainText($secret);
        $sha256 = new Sha256();

        try {
            $token = new JwtFacade()->parse(
                $tokenString,
                new SignedWith($sha256, $inMemory),
                new StrictValidAt($this->createClock()),
            );

            $sessionId = $token->claims()->get(self::SESSION_ID_KEY);
            if (! in_array($sessionId, [null, '', '0'], true)) {
                $arraySession->setId($sessionId);
            }

            $arraySession->start();

            $sessionData = $token->claims()->get(self::SESSION_DATA_KEY);
            if (is_array($sessionData)) {
                $arraySession->setStorageFromArray($sessionData);
                $this->originalSessionData = $sessionData;
            }

            // Store token claims for refresh check
            $this->tokenClaims = [
                'iat' => $token->claims()->get('iat'),
                'exp' => $token->claims()->get('exp'),
            ];
        } catch (InvalidArgumentException|RuntimeException) {
            $arraySession->start();
        }
    }

    private function writeSessionToHeader(ArraySession $arraySession, Response $response, string $secret): Response
    {
        $sessionData = $arraySession->getStorageAsArray();

        // If session is empty, don't return a token
        if ($sessionData === null || $sessionData === []) {
            return $response->withHeader('Set-Cookie', $this->deleteSessionTokenCookie());
        }

        $tokenString = $this->encodeSessionToJwt(
            $arraySession,
            $secret,
            $this->settings->get('session.lifetime')
        );

        return $response
            ->withHeader(self::TOKEN_HEADER, $tokenString)
            ->withHeader('Set-Cookie', $this->buildSessionTokenCookie($tokenString));
    }

    private function encodeSessionToJwt(ArraySession $arraySession, string $secret, int $lifetime): string
    {
        $inMemory = InMemory::plainText($secret);
        $sha256 = new Sha256();
        $now = new DateTimeImmutable();

        $sessionData = $arraySession->getStorageAsArray();

        $builder = Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates());

        $unencryptedToken = $builder
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify(sprintf('+%d seconds', $lifetime)))
            ->identifiedBy(Uuid::uuid4()->toString())
            ->relatedTo($arraySession->getId())
            ->withClaim(self::SESSION_DATA_KEY, $sessionData)
            ->getToken($sha256, $inMemory);

        return $unencryptedToken->toString();
    }

    private function createClock(): ClockInterface
    {
        return new class() implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
    }

    private function buildSessionTokenCookie(string $token): string
    {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        $parts = [
            self::SESSION_TOKEN_COOKIE . '=' . urlencode($token),
            'Path=/',
            'SameSite=Lax',
        ];

        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function deleteSessionTokenCookie(): string
    {
        return sprintf(
            '%s=; Path=/; Max-Age=0; Expires=%s; SameSite=Lax',
            self::SESSION_TOKEN_COOKIE,
            gmdate('D, d M Y H:i:s T', 0)
        );
    }

}
