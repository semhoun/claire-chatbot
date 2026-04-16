<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Auth;
use App\Services\OidcClient;
use App\Services\Session\SessionInterface;
use App\Services\Session\Trait\SessionFromRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class AuthController
{
    use SessionFromRequest;

    public function __construct(
        private OidcClient $oidcClient,
        private Auth $auth,
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
