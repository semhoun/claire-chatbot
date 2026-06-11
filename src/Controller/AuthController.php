<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Auth;
use App\Services\JwtTokenService;
use App\Services\OidcClient;
use App\Services\Session\SessionInterface;
use App\Services\Session\Trait\SessionFromRequest;
use JsonException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class AuthController
{
    use SessionFromRequest;

    public function __construct(
        private \Psr\Log\LoggerInterface $logger,
        private OidcClient $oidcClient,
        private Auth $auth,
        private JwtTokenService $jwtTokenService,
        private Twig $twig,
    ) {
    }

    public function ssoRedirect(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $authUrl = $this->oidcClient->getAuthorizationUrl($session);
        return $response->withHeader('Location', $authUrl)->withStatus(302);
    }

    public function ssoCallback(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return $response->withStatus(500);
        }

        $result = $this->oidcClient->handleCallback($session, $request->getQueryParams());

        if (! ($result['logged'] ?? false)) {
            // If the OAuth provider returned an error, display an error page
            if (isset($result['error'])) {
                $errorDescription = $result['error_description'] ?? 'Authorization failed';

                return $this->twig->render($response, 'error/default.twig', [
                    'code' => 403,
                    'title' => 'Accès refusé: ' . $result['error'],
                    'message' => $errorDescription,
                ])->withStatus(403);
            }

            // Auth uniquement via SSO: en cas d'échec, on renvoie vers l'init SSO
            return $response->withHeader('Location', '/auth/sso')->withStatus(302);
        }

        if ($result['id'] === null) {
            $this->logger->warning('No user id returned from SSO');
            return $response->withStatus(500);
        }

        $this->auth->login($session, $result['id'], $result['data']);

        $sessionToken = $this->jwtTokenService->generateSessionToken($session);
        $miniToken = $this->jwtTokenService->generateMiniToken($session);

        // Render callback page that stores token client-side then redirects
        // This avoids losing tokens on a 302 redirect while keeping
        // sensitive JWT values out of the URL.
        return $this->twig->render($response, 'auth_callback.twig', [
            'redirect_url' => '/',
            'session_token' => $sessionToken,
            'mini_token' => $miniToken,
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return $response->withStatus(500);
        }

        $this->auth->logout($session);
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    public function embedExchange(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
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
                ['error' => 'unauthorized'],
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
        $miniToken = $this->jwtTokenService->generateMiniToken($session);

        return $this->jsonResponse($response, [
            'session_token' => $sessionToken,
            'mini_token' => $miniToken,
        ]);
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
            ->withHeader('Content-Type', 'application/json');
    }
}
