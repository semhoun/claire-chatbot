<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Auth;
use App\Services\OidcClient;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

/**
 * Global authentication middleware.
 *
 * If the user is not authenticated (session 'logged' is falsy),
 * this middleware will render the welcome page for HTML requests,
 * or return a 401 JSON payload for API requests.
 *
 * Public paths are whitelisted to avoid redirect/render loops
 * and to allow access to static assets and login flows.
 */
final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private ContainerInterface $container,
        private Settings $settings,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        $publicRoutes = $this->settings->get('security.public_routes');
        if (array_any($publicRoutes, static fn ($prefix): bool => $path === $prefix || str_starts_with($path, rtrim((string) $prefix, '/') . '/'))) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return $handler->handle($request);
        }

        if ($this->auth->isAuthenticated($session)) {
            return $handler->handle($request);
        }

        $oidcClient = $this->container->get(OidcClient::class);
        if (! $oidcClient->isEnabled()) {
            $this->auth->login($session, $oidcClient->getDefaultUserId(), $oidcClient->getDefaultUserData());
            return $handler->handle($request);
        }

        $accept = $request->getHeaderLine('Accept');

        // JSON/API request
        if (str_contains($accept, 'application/json')) {
            $res = new SlimResponse(401);
            $res->getBody()->write((string) json_encode(['error' => 'unauthorized']));
            return $res->withHeader('Content-Type', 'application/json');
        }

        // HTML request: render welcome.twig directly
        $response = new SlimResponse(200);
        return $this->twig->render($response, 'welcome.twig');
    }
}
