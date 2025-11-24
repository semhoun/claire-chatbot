<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\OidcClient;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class AuthController
{
    public function __construct(
        private SessionInterface $session,
        private OidcClient $oidcClient
    ) {
    }

    public function ssoRedirect(Request $request, Response $response): Response
    {
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

        $this->session->set('logged', true);
        $this->session->set('uinfo', $result['uinfo']);
        if (! $this->session->has('chatId')) {
            $this->session->set('chatId', uniqid('USER_ ', true));
        }

        return $response->withStatus(302)->withHeader('Location', '/');
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->session->set('logged', false);
        $this->session->unset('uinfo');
        return $response->withStatus(302)->withHeader('Location', '/');
    }
}
