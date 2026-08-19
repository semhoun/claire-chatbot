<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class CorsHeaders
{
    public function __construct(
        private Settings $settings,
    ) {
    }

    public function apply(Request $request, Response $response): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $allowedOrigins = $this->settings->get('security.cors.allowed_origins');

        if ($origin === '') {
            $allowOrigin = '*';
        } elseif (in_array('*', $allowedOrigins, true)) {
            $allowOrigin = '*';
        } elseif (in_array($origin, $allowedOrigins, true)) {
            $allowOrigin = $origin;
            $response = $response
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        } else {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader(
                'Access-Control-Allow-Methods',
                'GET, POST, PUT, PATCH, DELETE, OPTIONS'
            )
            ->withHeader('Access-Control-Allow-Headers', '*')
            ->withHeader(
                'Access-Control-Expose-Headers',
                'X-Claire-Token, X-Claire-Minitoken'
            )
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader(
                'Cache-Control',
                'no-store, no-cache, must-revalidate, max-age=0'
            )
            ->withHeader('Pragma', 'no-cache');
    }
}
