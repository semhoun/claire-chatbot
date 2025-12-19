<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception;
use App\Services\Auth;
use App\Services\OidcClient;
use Doctrine\ORM\EntityManagerInterface;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class AuthController
{
    public function __construct(
        private SessionInterface $session,
        private OidcClient $oidcClient,
        private readonly Auth $auth,
    ) {
    }

    public function ssoRedirect(Request $request, Response $response): Response
    {
        if (!$this->oidcClient->isEnabled()) {
            $this->auth->login($this->oidcClient->getDefaultUser());
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        $authUrl = $this->oidcClient->getAuthorizationUrl($this->session);
        return $response->withHeader('Location', $authUrl)->withStatus(302);
    }

    public function ssoCallback(Request $request, Response $response): Response
    {
        $result = $this->oidcClient->handleCallback($this->session, $request->getQueryParams());
        if (! ($result['logged'] ?? false)) {
            // Auth uniquement via SSO: en cas d'échec, on renvoie vers l'init SSO
            return $response->withHeader('Location', '/auth/sso')->withStatus(302);
        }

        $this->auth->login($result['uinfo']);
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();
        return $response->withStatus(302)->withHeader('Location', '/');
    }
}
