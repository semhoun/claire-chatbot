<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Entity\User;
use App\Services\Auth;
use App\Services\JwtTokenService;
use App\Services\Settings;
use App\Services\Session\ArraySession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\NonBufferedBody;
use Slim\Routing\RouteContext;

/**
 * Middleware for JWT-based session management via X-Claire-Auth header.
 *
 * Reads JWT from X-Claire-Auth header or `token` query parameter,
 * populates session data, stores the session in request attributes,
 * and returns the session token in X-Claire-Token response header when
 * the session is modified or refreshed.
 */
final class JwtSessionMiddleware implements MiddlewareInterface
{
    public const string SESSION_ATTRIBUTE = 'session';

    private const string AUTH_HEADER = 'X-Claire-Auth';

    private const string TOKEN_HEADER = 'X-Claire-Token';

    private const string MINI_TOKEN_HEADER = 'X-Claire-Minitoken';

    private const string SESSION_TOKEN_QUERY = 'token';

    /** @var array<string, mixed> */
    private array $tokenClaims = [];

    /** @var array<string, mixed>|null */
    private ?array $originalSessionData = null;

    private bool $authenticatedViaMiniToken = false;

    public function __construct(
        private readonly Auth $auth,
        private readonly EntityManager $entityManager,
        private readonly JwtTokenService $jwtTokenService,
        private readonly Settings $settings,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->isNotFoundRoute($request)) {
            return $handler->handle($request);
        }

        $tokenString = $this->extractBearerToken($request);

        $this->tokenClaims = [];
        $this->originalSessionData = null;
        $this->authenticatedViaMiniToken = false;

        // Create a new session instance for this request (not a singleton)
        $arraySession = new ArraySession();

        if ($tokenString !== null) {
            $this->decodeAndPopulateSession($arraySession, $tokenString);
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
            return $this->writeSessionToHeader($arraySession, $response);
        }

        return $response;
    }

    private function isNotFoundRoute(Request $request): bool
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();

        return $route?->getName() === 'not-found';
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->getHeaderLine(self::AUTH_HEADER);

        if ($authHeader !== '' && $authHeader !== '0') {
            return $authHeader;
        }

        $queryToken = trim((string) (($request->getQueryParams()[self::SESSION_TOKEN_QUERY] ?? '')));
        if ($queryToken !== '' && $queryToken !== '0') {
            return $queryToken;
        }

        return null;
    }

    private function shouldReturnToken(ArraySession $arraySession): bool
    {
        // When authenticated via mini token, promote to session token in headers.
        if ($this->authenticatedViaMiniToken) {
            return true;
        }

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

    private function decodeAndPopulateSession(ArraySession $arraySession, string $tokenString): void
    {
        $miniUserId = $this->jwtTokenService->extractMiniUserId($tokenString);
        if ($miniUserId !== null) {
            $this->authenticatedViaMiniToken = true;
            $arraySession->start();
            $this->rebuildSessionFromMiniToken($arraySession, $miniUserId);
            $this->originalSessionData = $arraySession->getStorageAsArray();

            return;
        }

        $parsedToken = $this->jwtTokenService->parseSessionToken($tokenString);
        if ($parsedToken === null) {
            $arraySession->start();

            return;
        }

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

    private function rebuildSessionFromMiniToken(ArraySession $arraySession, mixed $userIdClaim): void
    {
        if (! is_string($userIdClaim) || $userIdClaim === '' || $userIdClaim === '0') {
            return;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->find($userIdClaim);
        if (! $user instanceof User) {
            return;
        }

        $data = [
            'firstName' => $user->getFirstName() ?? '',
            'lastName' => $user->getLastName() ?? '',
            'email' => $user->getEmail() ?? '',
        ];

        $arraySession->clear();
        $this->auth->login($arraySession, $userIdClaim, $data);
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

        $response = $response->withHeader(self::TOKEN_HEADER, $tokenString);

        $userId = (string) $arraySession->get(Auth::USERID, '');
        if ($userId === '' || $userId === '0') {
            return $response;
        }

        $miniToken = $this->jwtTokenService->generateMiniToken($arraySession, $lifetime);

        return $response->withHeader(self::MINI_TOKEN_HEADER, $miniToken);
    }
}
