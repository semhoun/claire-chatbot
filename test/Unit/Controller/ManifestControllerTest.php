<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\ManifestController;
use App\Services\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ManifestControllerTest extends TestCase
{
    #[DataProvider('manifestCases')]
    public function testManifestReturnsConfiguredNameAndRelativePaths(string $name, string $basePath): void
    {
        $manifestController = new ManifestController(new Settings(['name' => $name]));
        $serverRequest = new ServerRequestFactory()->createServerRequest(
            'GET',
            'https://example.com' . $basePath . '/manifest.webmanifest',
        );

        $response = $manifestController->index($serverRequest, new Response());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/manifest+json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
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
        ], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, string}> */
    public static function manifestCases(): iterable
    {
        yield 'default name at root' => ['Claire', ''];
        yield 'default name under base path' => ['Claire', '/chat'];
        yield 'custom name at root' => ['<Claire> & "Team"', ''];
        yield 'custom name under base path' => ['<Claire> & "Team"', '/chat'];
    }
}
