<?php

declare(strict_types=1);

namespace App\Controller;

use App\Renderer\VueShell;
use App\Entity\ChatHistory;
use App\Entity\File;
use App\Middleware\JwtSessionMiddleware;
use App\Services\Auth;
use App\Services\ChatGenerationState;
use App\Services\JwtTokenService;
use App\Services\OidcClient;
use App\Services\OidcTransaction;
use App\Services\RememberSession;
use App\Services\Session\SessionInterface;
use App\Services\Session\SessionManagerInterface;
use App\Services\Session\Trait\SessionFromRequest;
use JsonException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class AuthController
{
    use SessionFromRequest;

    public function __construct(
        private \Psr\Log\LoggerInterface $logger,
        private OidcClient $oidcClient,
        private Auth $auth,
        private JwtTokenService $jwtTokenService,
        private VueShell $vueShell,
        private OidcTransaction $oidcTransaction,
        private \Doctrine\ORM\EntityManager $entityManager,
        private ChatGenerationState $chatGenerationState,
        private RememberSession $rememberSession,
    ) {
    }

    public function ssoRedirect(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $authUrl = $this->oidcClient->getAuthorizationUrl($session);
        $cookie = $this->oidcTransaction->issue((string) $session->get('oidc_state'));
        $session->delete('oidc_state');
        $session->delete('oidc_state_expires');
        return $response->withHeader('Location', $authUrl)
            ->withAddedHeader('Set-Cookie', $cookie)
            ->withHeader('Cache-Control', 'no-store')->withStatus(302);
    }

    public function ssoCallback(Request $request, Response $response): Response
    {
        $response = $response->withAddedHeader('Set-Cookie', $this->oidcTransaction->clearCookie())
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionManagerInterface) {
            return $response->withStatus(500);
        }

        $session->delete('oidc_state');
        $session->delete('oidc_state_expires');

        $state = $this->oidcTransaction->consume($request->getCookieParams()[OidcTransaction::COOKIE] ?? null);
        if ($state === null) {
            return $this->vueShell->respond($response->withStatus(403), [
                'page' => 'error', 'code' => 403, 'title' => 'Session de connexion expirée ou invalide.',
                'baseUrl' => (string) $request->getAttribute('base_url'),
            ]);
        }

        $session->set('oidc_state', $state);
        $session->set('oidc_state_expires', time() + OidcTransaction::TTL);

        $result = $this->oidcClient->handleCallback($session, $request->getQueryParams());

        if (! ($result['logged'] ?? false)) {
            // If the OAuth provider returned an error, display an error page
            if (isset($result['error'])) {
                $errorDescription = $result['error_description'] ?? 'Authorization failed';

                return $this->vueShell->respond($response, [
                    'page' => 'error',
                    'baseUrl' => (string) $request->getAttribute('base_url'),
                    'code' => 403,
                    'title' => 'Accès refusé: ' . $result['error'],
                    'details' => ['message' => $errorDescription],
                ])->withHeader('Content-Type', 'text/html; charset=utf-8')
                    ->withStatus(403);
            }

            // Auth uniquement via SSO: en cas d'échec, on renvoie vers l'init SSO
            return $response->withHeader('Location', '/auth/sso')->withStatus(302);
        }

        if ($result['id'] === null) {
            $this->logger->warning('No user id returned from SSO');
            return $response->withStatus(500);
        }

        $this->auth->login($session, $result['id'], $result['data']);

        $this->rememberSession->revoke($request->getCookieParams()[RememberSession::COOKIE] ?? null);
        $response = $response->withAddedHeader('Set-Cookie', $this->rememberSession->issue($session));

        $sessionToken = $this->jwtTokenService->generateSessionToken($session);

        // Render callback page that stores token client-side then redirects
        // This avoids losing tokens on a 302 redirect while keeping
        // sensitive JWT values out of the URL.
        return $this->vueShell->respond($response, [
            'page' => 'callback',
            'baseUrl' => (string) $request->getAttribute('base_url'),
            'sessionToken' => $sessionToken,
            'redirectUrl' => '/',
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function logout(Request $request, Response $response): Response
    {
        if (! $this->rememberSession->allows($request)) {
            return $this->jsonResponse($response, ['error' => 'forbidden'], 403);
        }
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return $response->withStatus(500);
        }

        $this->rememberSession->revoke($request->getCookieParams()[RememberSession::COOKIE] ?? null);
        $this->auth->logout($session);
        return $response->withStatus(204)->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Set-Cookie', $this->rememberSession->clearCookie());
    }

    public function remember(Request $request, Response $response): Response
    {
        if (! $this->rememberSession->allows($request)) {
            return $this->jsonResponse($response, ['error' => 'forbidden'], 403);
        }
        $id = $this->rememberSession->cookieId($request->getCookieParams()[RememberSession::COOKIE] ?? null);
        $data = $this->rememberSession->find($id);
        $session = $this->getSession($request);
        if ($data === null || ! $this->auth->restore($session, $data['user_id'])) {
            $this->rememberSession->revoke($request->getCookieParams()[RememberSession::COOKIE] ?? null);
            return $this->jsonResponse($response, ['error' => 'unauthorized'], 401)
                ->withAddedHeader('Set-Cookie', $this->rememberSession->clearCookie());
        }
        $session->set(RememberSession::ID, $id);
        $session->set(RememberSession::EXPIRES, $data['expires']);
        // JWT middleware emits the short-lived header. Never extend the cookie or Redis TTL here.
        return $response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }

    public function embedExchange(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionManagerInterface) {
            return $this->jsonResponse(
                $response,
                ['error' => 'session_unavailable'],
                500
            );
        }

        $payload = $this->extractEmbedExchangePayload($request);
        $ssoToken = trim((string) ($payload['sso_token'] ?? ''));
        $tokenType = $payload['sso_token_type'] ?? null;

        if ($ssoToken === '') {
            return $this->jsonResponse(
                $response,
                ['error' => 'missing_sso_token'],
                400
            );
        }

        if ($tokenType !== null) {
            $tokenType = strtolower(trim((string) $tokenType));
            if (
                $tokenType !== 'access_token'
                && $tokenType !== 'id_token'
            ) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'invalid_sso_token_type'],
                    400
                );
            }
        }

        $exchangeResult = $this->oidcClient->resolveUserFromSsoToken(
            $ssoToken,
            $tokenType
        );

        if (($exchangeResult['logged'] ?? false) !== true) {
            $detectedType = $exchangeResult['token_type'] ?? 'unknown';
            $reason = $exchangeResult['reason'] ?? 'invalid_token';
            $this->logger->warning('Embed SSO exchange failed', [
                'token_type' => $detectedType,
                'reason' => $reason,
            ]);

            return $this->jsonResponse(
                $response,
                ['error' => 'unauthorized', 'reason' => $reason],
                401
            );
        }

        if (($exchangeResult['id'] ?? null) === null) {
            $this->logger->warning('Embed SSO exchange missing sub claim', [
                'token_type' => $exchangeResult['token_type'] ?? 'unknown',
            ]);

            return $this->jsonResponse(
                $response,
                ['error' => 'unauthorized'],
                401
            );
        }

        $this->auth->login(
            $session,
            (string) $exchangeResult['id'],
            $exchangeResult['data'] ?? []
        );

        $sessionToken = $this->jwtTokenService->generateSessionToken($session);

        return $this->jsonResponse($response, [
            'session_token' => $sessionToken,
        ]);
    }

    public function resourceToken(Request $request, Response $response): Response
    {
        $session = $request->getAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE);
        if ($request->getMethod() !== 'POST') {
            return $this->jsonResponse($response, ['error' => 'method_not_allowed'], 405);
        }

        if (! $session instanceof SessionInterface || $session->get(Auth::AUTHENTICATED) !== true
            || $request->getAttribute(JwtSessionMiddleware::AUTH_RESOURCE) !== null
            || ! is_int($request->getAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT))
            || $request->getAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT) <= time()) {
            return $this->jsonResponse($response, ['error' => 'unauthorized'], 401);
        }

        $userId = $session->get(Auth::USERID);
        if (! is_string($userId) || $userId === '' || $userId === '0') {
            return $this->jsonResponse($response, ['error' => 'unauthorized'], 401);
        }

        $payload = $this->extractEmbedExchangePayload($request);
        $batched = array_key_exists('resources', $payload);
        $resources = $batched ? $payload['resources'] : [$payload];
        if ($batched && ! $this->jwtTokenService->validResources($resources)) {
            return $this->jsonResponse($response, ['error' => 'invalid_resource_scope'], 400);
        }

        foreach ($resources as $payload) {
            $type = $payload['type'] ?? null;
            $fields = match ($type) {
                'file' => ['fileId'],
                'stream' => ['threadId', 'sessionId'],
                default => [],
            };
            if ($fields === []) {
                return $this->jsonResponse($response, ['error' => 'invalid_resource_type'], 400);
            }

            foreach ($fields as $field) {
                if (! is_string($payload[$field] ?? null) || $payload[$field] === ''
                    || strlen($payload[$field]) > 255 || preg_match('/[\x00-\x20\/\\\\?#]/', $payload[$field])) {
                    return $this->jsonResponse($response, ['error' => 'invalid_resource_scope'], 400);
                }
            }

            if ($type === 'file') {
                $file = $this->entityManager->getRepository(File::class)->findOneBy(['fileId' => $payload['fileId']]);
                if (! $file instanceof File || $file->getUser()->getId() !== $userId) {
                    return $this->jsonResponse($response, ['error' => 'resource_not_found'], 404);
                }
            } else {
                $thread = $this->entityManager->getRepository(ChatHistory::class)
                    ->findOneBy(['threadId' => $payload['threadId']]);
                if ($thread !== null && $thread->getUser()->getId() !== $userId) {
                    return $this->jsonResponse($response, ['error' => 'resource_not_found'], 404);
                }

                if ($thread === null) {
                    $state = $this->chatGenerationState->get($userId, $payload['threadId']);
                    if (($state['messageId'] ?? '') === ''
                        || ! in_array($state['status'] ?? '', ['queued', 'running', 'done', 'error'], true)) {
                        return $this->jsonResponse($response, ['error' => 'resource_not_found'], 404);
                    }
                }
            }
        }

        try {
            if ($batched) {
                $token = $this->jwtTokenService->generateResourcesToken(
                    $session, $resources, $request->getAttribute(JwtSessionMiddleware::AUTH_EXPIRES_AT)
                );
                $claims = $this->jwtTokenService->parseResourcesToken($token);
                return $this->jsonResponse($response, ['token' => $token, 'expiresAt' => $claims['expiresAt']]);
            }
            $token = $type === 'file'
                ? $this->jwtTokenService->generateFileToken($session, $payload['fileId'])
                : $this->jwtTokenService->generateStreamToken($session, $payload['threadId'], $payload['sessionId']);
        } catch (\InvalidArgumentException) {
            return $this->jsonResponse($response, ['error' => 'invalid_resource_scope'], 400);
        }

        $claims = $type === 'file'
            ? $this->jwtTokenService->parseFileToken($token)
            : $this->jwtTokenService->parseStreamToken($token);
        return $this->jsonResponse($response, ['token' => $token, 'expiresAt' => $claims['expiresAt']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEmbedExchangePayload(Request $request): array
    {
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            return $parsedBody;
        }

        $rawBody = (string) $request->getBody();
        if ($rawBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(
        Response $response,
        array $payload,
        int $status = 200
    ): Response {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Type', 'application/json');
    }
}
