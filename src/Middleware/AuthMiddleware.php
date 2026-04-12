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

        if ($this->isPublicRoute($request)) {
            return true;
        }

        $session = $request->getAttribute('session');
        if (! $session instanceof SessionInterface) {
            return true;
        }

        if ($this->auth->isAuthenticated($session)) {
            return true;
        }

        return $this->attemptAutoLogin($session);
    }

    private function isPublicRoute(Request $request): bool
    {
        $path = $request->getUri()->getPath();
        $publicRoutes = $this->settings->get('security.public_routes');

        return array_any($publicRoutes, static fn ($prefix): bool => $path === $prefix || str_starts_with($path, rtrim((string) $prefix, '/') . '/'));
    }

    private function attemptAutoLogin(SessionInterface $session): bool
    {
        $oidcClient = $this->container->get(OidcClient::class);

        if ($oidcClient->isEnabled()) {
            return false;
        }

        $this->auth->login($session, $oidcClient->getDefaultUserId(), $oidcClient->getDefaultUserData());

        return true;
    }

    private function handleUnauthorized(Request $request): Response
    {
        return $this->isJsonRequest($request)
            ? $this->createJsonUnauthorizedResponse()
            : $this->createHtmlUnauthorizedResponse();
    }

    private function isJsonRequest(Request $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function createJsonUnauthorizedResponse(): Response
    {
        $response = new SlimResponse(401);
        $response->getBody()->write((string) json_encode(['error' => 'unauthorized']));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function createHtmlUnauthorizedResponse(): Response
    {
        return $this->twig->render(new SlimResponse(200), 'welcome.twig');
    }
}
