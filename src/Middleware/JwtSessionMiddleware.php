<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Auth;
use App\Services\JwtTokenService;
use App\Services\RememberSession;
use App\Services\ResourceRequestScope;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Middleware for JWT-based session management via X-Claire-Auth header.
 *
 * Full sessions require X-Claire-Auth. Resource capabilities may also use
 * the `token` query parameter and never issue or refresh session credentials.
 */
final class JwtSessionMiddleware implements MiddlewareInterface
{
    public const string SESSION_ATTRIBUTE = 'session';

    public const string AUTH_EXPIRES_AT = 'auth_expires_at';

    public const string AUTH_RESOURCE = 'auth_resource';

    private const string AUTH_HEADER = 'X-Claire-Auth';

    private const string TOKEN_HEADER = 'X-Claire-Token';

    private const string SESSION_TOKEN_QUERY = 'token';

    /** @var array<string, mixed> */
    private array $tokenClaims = [];

    /** @var array<string, mixed>|null */
    private ?array $originalSessionData = null;

    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly Settings $settings,
        private readonly RememberSession $rememberSession,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $tokenString = $this->extractBearerToken($request);

        $this->tokenClaims = [];
        $this->originalSessionData = null;

        // Create a new session instance for this request (not a singleton)
        $arraySession = new ArraySession();

        if ($tokenString !== null) {
            $resource = $this->jwtTokenService->parseFileToken($tokenString)
                ?? $this->jwtTokenService->parseStreamToken($tokenString)
                ?? $this->jwtTokenService->parseResourcesToken($tokenString);
            if ($resource !== null) {
                if (! ResourceRequestScope::matches($request, $resource)) {
                    return new \Slim\Psr7\Response(403);
                }

                $arraySession->start();
                $arraySession->set(Auth::USERID, $resource['userId']);
                $arraySession->set(Auth::AUTHENTICATED, true);
                if (array_key_exists(RememberSession::ID, $resource)) {
                    $arraySession->set(RememberSession::ID, $resource[RememberSession::ID]);
                    $arraySession->set(RememberSession::EXPIRES, $resource[RememberSession::EXPIRES] ?? null);
                    if (! $this->rememberSession->valid($arraySession)) {
                        return (new \Slim\Psr7\Response(401))->withHeader('Cache-Control', 'no-store');
                    }
                }
                $request = $request->withAttribute(self::SESSION_ATTRIBUTE, $arraySession)
                    ->withAttribute(self::AUTH_RESOURCE, $resource)
                    ->withAttribute(self::AUTH_EXPIRES_AT, $resource['expiresAt']);
                return $handler->handle($request)
                    ->withoutHeader(self::TOKEN_HEADER)->withoutHeader('X-Claire-Minitoken')
                    ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
            }

            // General credentials are accepted only through explicit API authentication.
            $parsedSession = $this->jwtTokenService->parseSessionToken($tokenString);
            if ($request->getHeaderLine(self::AUTH_HEADER) === '' || $parsedSession === null) {
                return new \Slim\Psr7\Response(401);
            }

            $this->decodeAndPopulateSession($arraySession, $parsedSession);
            if (! $this->rememberSession->valid($arraySession)) {
                return (new \Slim\Psr7\Response(401))->withHeader('Cache-Control', 'no-store');
            }
            $request = $request->withAttribute(self::AUTH_EXPIRES_AT, $this->tokenClaims['exp']->getTimestamp());
        } else {
            $arraySession->start();
        }

        // Store session in request attributes
        $request = $request->withAttribute(self::SESSION_ATTRIBUTE, $arraySession);

        $response = $handler->handle($request);

        // Return X-Claire-Token header if data changed or token needs refresh
        if ($this->shouldReturnToken($arraySession)) {
            return $this->writeSessionToHeader($arraySession, $response);
        }

        return $response;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->getHeaderLine(self::AUTH_HEADER);

        if ($authHeader !== '' && $authHeader !== '0') {
            return $authHeader;
        }

        $queryToken = $request->getQueryParams()[self::SESSION_TOKEN_QUERY] ?? '';
        if (! is_string($queryToken)) {
            return 'invalid-token';
        }

        $queryToken = trim($queryToken);
        if ($queryToken !== '' && $queryToken !== '0') {
            return $queryToken;
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

        // Check if token needs refresh (absolute margin before expiration)
        $issuedAt = $this->tokenClaims['iat'] ?? null;
        $expiresAt = $this->tokenClaims['exp'] ?? null;

        if ($issuedAt instanceof DateTimeImmutable && $expiresAt instanceof DateTimeImmutable) {
            return $this->shouldRefreshToken($issuedAt, $expiresAt);
        }

        return false;
    }

    private function shouldRefreshToken(DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): bool
    {
        if ($expiresAt <= $issuedAt) {
            return true;
        }

        $now = new DateTimeImmutable();
        $secondsBeforeExpire = $expiresAt->getTimestamp() - $now->getTimestamp();
        $refreshBeforeExpire = max(
            0,
            (int) $this->settings->get('session.refresh_before_expire')
        );

        return $secondsBeforeExpire <= $refreshBeforeExpire;
    }

    /** @param array<string,mixed> $parsedToken */
    private function decodeAndPopulateSession(ArraySession $arraySession, array $parsedToken): void
    {
        $sessionId = $parsedToken['sessionId'];
        if (! in_array($sessionId, [null, '', '0'], true)) {
            $arraySession->setId((string) $sessionId);
        }

        $arraySession->start();

        $sessionData = $parsedToken['sessionData'];
        if (is_array($sessionData)) {
            $arraySession->setStorageFromArray($sessionData);
            $this->originalSessionData = $sessionData;
        }

        // Store token claims for refresh check
        $this->tokenClaims = [
            'iat' => $parsedToken['issuedAt'],
            'exp' => $parsedToken['expiresAt'],
        ];
    }

    private function writeSessionToHeader(ArraySession $arraySession, Response $response): Response
    {
        $sessionData = $arraySession->getStorageAsArray();

        // If session is empty, don't return a token
        if ($sessionData === null || $sessionData === []) {
            return $response;
        }

        $lifetime = $this->jwtTokenService->ttl();
        $tokenString = $this->jwtTokenService->generateSessionToken($arraySession, $lifetime);

        return $response->withHeader(self::TOKEN_HEADER, $tokenString);
    }
}
