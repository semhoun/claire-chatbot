<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\CorsHeaders;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\NonBufferedBody;

/**
 * Middleware pour gérer CORS (Cross-Origin Resource Sharing).
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CorsHeaders $corsHeaders,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);
        if ($response->getBody() instanceof NonBufferedBody) {
            return $response;
        }

        return $this->corsHeaders->apply($request, $response);
    }
}
