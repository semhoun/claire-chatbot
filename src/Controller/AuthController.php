<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Auth;
use App\Services\OidcClient;
use App\Services\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class AuthController
{
    public function __construct(
        private OidcClient $oidcClient,
        private Auth $auth,
    ) {
    }

    public function ssoRedirect(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return $response->withStatus(500);
        }

        if (! $this->oidcClient->isEnabled()) {
            $this->auth->login($session, $this->oidcClient->getDefaultUserId(), $this->oidcClient->getDefaultUserData());
            return $response->withStatus(302)->withHeader('Location', '/');
        }

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
            // Auth uniquement via SSO: en cas d'échec, on renvoie vers l'init SSO
            return $response->withHeader('Location', '/auth/sso')->withStatus(302);
        }

        if ($result['id'] === null) {
            return $response->withStatus(500);
        }

        $this->auth->login($session, $result['id'], $result['data']);
        return $response->withStatus(302)->withHeader('Location', '/');
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
}
