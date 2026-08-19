<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Auth;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Slim\Routing\RouteContext;
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
        private Settings $settings,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->shouldAllowRequest($request)) {
            return $handler->handle($request);
        }

        return $this->handleUnauthorized($request);
    }

    private function shouldAllowRequest(Request $request): bool
    {
        if ($request->getMethod() === 'OPTIONS') {
            return true;
        }

        if ($this->isNotFoundRoute($request)) {
            return true;
        }

        if ($this->isPublicRoute($request)) {
            return true;
        }

        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return true;
        }

        return $this->auth->isAuthenticated($session);
    }

    private function isNotFoundRoute(Request $request): bool
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();

        return $route?->getName() === 'not-found';
    }

    private function isPublicRoute(Request $request): bool
    {
        $path = $request->getUri()->getPath();
        $publicRoutes = $this->settings->get('security.public_routes');

        return array_any($publicRoutes, static fn ($prefix): bool => $path === $prefix || str_starts_with($path, rtrim((string) $prefix, '/') . '/'));
    }

    private function handleUnauthorized(Request $request): Response
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $response = new SlimResponse(401);
            $response->getBody()->write((string) json_encode(['error' => 'unauthorized']));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $this->twig->render(new SlimResponse(200), 'welcome.twig', [
            'base_url' => (string) $request->getAttribute('base_url'),
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
