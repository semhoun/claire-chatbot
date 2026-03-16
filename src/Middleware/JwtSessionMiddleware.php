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
 * Middleware for JWT-based session management via cookies.
 *
 * Reads JWT from cookie, populates session data, and writes back
 * the session as a JWT cookie at the end of the request.
 */
final class JwtSessionMiddleware implements MiddlewareInterface
{
    public const string SESSION_ATTRIBUTE = 'session';

    private const string SESSION_ID_KEY = 'sub';

    private const string SESSION_DATA_KEY = 'data';

    private const string DEFAULT_COOKIE_NAME = 'jwt_session';

    private const int DEFAULT_LIFETIME = 7200;

    private readonly ArraySession $arraySession;

    /**
     * @var array<string, mixed>
     */
    private array $tokenClaims = [];

    private ?array $originalSessionData = null;

    public function __construct(
        private readonly Settings $settings,
    ) {
        $this->arraySession = new ArraySession();
    }

    public function process(Request $request, Handler $handler): Response
    {
        $cookieName = $this->getCookieName();
        $secret = $this->getSecret();

        $cookies = $request->getCookieParams();
        $tokenString = $cookies[$cookieName] ?? null;

        $this->tokenClaims = [];

        if (! in_array($tokenString, [null, '', '0'], true)) {
            $this->decodeAndPopulateSession($tokenString, $secret);
        } else {
            $this->arraySession->start();
        }

        // Add session to request for controllers
        $request = $request->withAttribute(self::SESSION_ATTRIBUTE, $this->arraySession);

        $response = $handler->handle($request);

        // Don't modify headers if response is streaming (NonBufferedBody)
        // as output has already started
        if ($response->getBody() instanceof NonBufferedBody) {
            return $response;
        }

        // Only write cookie if data changed or token needs refresh
        if ($this->shouldWriteCookie()) {
            return $this->writeSessionToCookie($response, $secret);
        }

        return $response;
    }

    private function shouldWriteCookie(): bool
    {
        // If session data changed, write cookie
        $currentData = $this->arraySession->getStorageAsArray();
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

    /**
     * @return array{
     *   cookie_name: string,
     *   secret: string|null,
     *   lifetime: int,
     *   secure: bool,
     *   httponly: bool,
     *   domain: string|null,
     *   path: string
     * }
     */
    private function getJwtConfig(): array
    {
        try {
            $config = $this->settings->get('session');
        } catch (RuntimeException) {
            $config = [];
        }

        return [
            'cookie_name' => $config['name'] ?? self::DEFAULT_COOKIE_NAME,
            'secret' => $config['jwt_secret'] ?? null,
            'lifetime' => $config['lifetime'] ?? self::DEFAULT_LIFETIME,
            'secure' => $config['secure'] ?? false,
            'httponly' => $config['httponly'] ?? true,
            'domain' => $config['domain'] ?? null,
            'path' => $config['path'] ?? '/',
        ];
    }

    private function getCookieName(): string
    {
        return $this->getJwtConfig()['cookie_name'];
    }

    private function getSecret(): string
    {
        $secret = $this->getJwtConfig()['secret'];

        if ($secret === null || $secret === '') {
            throw new RuntimeException('JWT secret key is not configured');
        }

        return $secret;
    }

    private function decodeAndPopulateSession(string $tokenString, string $secret): void
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
                $this->arraySession->setId($sessionId);
            }

            $this->arraySession->start();

            $sessionData = $token->claims()->get(self::SESSION_DATA_KEY);
            if (is_array($sessionData)) {
                $this->arraySession->setStorageFromArray($sessionData);
                $this->originalSessionData = $sessionData;
            }

            // Store token claims for refresh check
            $this->tokenClaims = [
                'iat' => $token->claims()->get('iat'),
                'exp' => $token->claims()->get('exp'),
            ];
        } catch (InvalidArgumentException|RuntimeException) {
            $this->arraySession->start();
        }
    }

    private function writeSessionToCookie(Response $response, string $secret): Response
    {
        $config = $this->getJwtConfig();
        $sessionData = $this->arraySession->getStorageAsArray();

        // If session is empty, delete the cookie
        if ($sessionData === null || $sessionData === []) {
            return $response->withHeader('Set-Cookie', $this->deleteCookie(
                $config['cookie_name'],
                $config['path'],
                $config['domain'],
                $config['secure'],
                $config['httponly']
            ));
        }

        $cookieName = $config['cookie_name'];
        $lifetime = $config['lifetime'];
        $secure = $config['secure'];
        $httponly = $config['httponly'];
        $domain = $config['domain'];
        $path = $config['path'];

        $tokenString = $this->encodeSessionToJwt($secret, $lifetime);

        $cookieValue = $this->buildCookieValue(
            $cookieName,
            $tokenString,
            $lifetime,
            $path,
            $domain,
            $secure,
            $httponly,
        );

        return $response->withHeader('Set-Cookie', $cookieValue);
    }

    private function encodeSessionToJwt(string $secret, int $lifetime): string
    {
        $inMemory = InMemory::plainText($secret);
        $sha256 = new Sha256();
        $now = new DateTimeImmutable();

        $sessionData = $this->arraySession->getStorageAsArray();

        $builder = Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates());

        $unencryptedToken = $builder
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify(sprintf('+%d seconds', $lifetime)))
            ->identifiedBy(Uuid::uuid4()->toString())
            ->relatedTo($this->arraySession->getId())
            ->withClaim(self::SESSION_DATA_KEY, $sessionData)
            ->getToken($sha256, $inMemory);

        return $unencryptedToken->toString();
    }

    private function buildCookieValue(
        string $name,
        string $value,
        int $lifetime,
        string $path,
        ?string $domain,
        bool $secure,
        bool $httponly,
    ): string {
        $parts = [
            $name . '=' . urlencode($value),
            'Path=' . $path,
            'Max-Age=' . $lifetime,
        ];

        if ($domain !== null && $domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        if ($secure) {
            $parts[] = 'Secure';
        }

        if ($httponly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=Lax';

        return implode('; ', $parts);
    }

    private function deleteCookie(
        string $name,
        string $path,
        ?string $domain,
        bool $secure,
        bool $httponly,
    ): string {
        $parts = [
            $name . '=',
            'Path=' . $path,
            'Max-Age=0',
            'Expires=' . gmdate('D, d M Y H:i:s T', 0),
        ];

        if ($domain !== null && $domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        if ($secure) {
            $parts[] = 'Secure';
        }

        if ($httponly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=Lax';

        return implode('; ', $parts);
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
}
