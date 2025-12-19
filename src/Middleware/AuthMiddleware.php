<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Auth;
use App\Services\OidcClient;
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
    private const array publicPrefixes = [
        '/health',
        '/logout',
        '/auth',
        '/css',
        '/js',
        '/image',
        '/files/by-token/',
    ];

    public function __construct(
        private Twig $twig,
        private readonly Auth $auth,
        private ContainerInterface $container,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->auth->isAuthenticated()) {
            return $handler->handle($request);
        }

        if (!$this->container->get(OidcClient::class)->isEnabled()) {
            $this->auth->login($this->container->get(OidcClient::class)->getDefaultUser());
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (array_any(self::publicPrefixes, static fn ($prefix): bool => $path === $prefix || str_starts_with($path, rtrim((string) $prefix, '/') . '/'))) {
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
