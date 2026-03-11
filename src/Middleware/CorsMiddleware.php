<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Middleware pour gérer CORS (Cross-Origin Resource Sharing).
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        // Gérer la requête de pré-vérification (OPTIONS)
        if ($request->getMethod() === 'OPTIONS') {
            $response = new SlimResponse();
            return $this->addCorsHeaders($request, $response);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($request, $response);
    }

    private function addCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->getHeaderLine('Origin');

        // On peut restreindre ici les origines autorisées si nécessaire.
        // Pour un widget d'intégration, on autorise souvent l'origine demanderesse.
        if ($origin !== '') {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        } else {
            // Fallback si pas de header Origin (cas peu probable pour fetch CORS)
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', '*')
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }
}
