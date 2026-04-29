<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\NonBufferedBody;

/**
 * Middleware pour gérer CORS (Cross-Origin Resource Sharing).
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Settings $settings
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        // Don't modify headers if response is streaming (NonBufferedBody)
        // as output has already started
        if ($response->getBody() instanceof NonBufferedBody) {
            return $response;
        }

        return $this->addCorsHeaders($request, $response);
    }

    private function addCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $allowedOrigins = $this->settings->get('security.cors.allowed_origins', ['*']);

        $allowOrigin = '*';
        $allowCredentials = 'false';

        if ($origin !== '') {
            if (in_array('*', $allowedOrigins, true)) {
                $allowOrigin = '*';
                $allowCredentials = 'false';
            } elseif (in_array($origin, $allowedOrigins, true)) {
                $allowOrigin = $origin;
                $allowCredentials = 'true';
            } else {
                // Origin not allowed
                return $response;
            }
        }

        $response = $response->withHeader('Access-Control-Allow-Origin', $allowOrigin);
        if ($allowCredentials === 'true') {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', '*')
            ->withHeader('Access-Control-Expose-Headers', 'X-Claire-Token, X-Claire-Minitoken')
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }
}
