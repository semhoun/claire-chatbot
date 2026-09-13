<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class ManifestController
{
    public function __construct(private Settings $settings)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $name = (string) $this->settings->get('name');
        // Omit id so it defaults to the mount-relative start_url.
        $manifest = [
            'name' => $name,
            'short_name' => $name,
            'start_url' => './',
            'scope' => './',
            'display' => 'standalone',
            'lang' => 'fr',
            'theme_color' => '#ff3cac',
            'background_color' => '#1e1030',
            'icons' => [
                [
                    'src' => 'image/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => 'image/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
            ],
        ];

        $response->getBody()->write(json_encode($manifest, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/manifest+json');
    }
}
