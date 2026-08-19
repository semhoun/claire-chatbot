<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\CorsHeaders;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CorsHeadersTest extends TestCase
{
    public function testApplyAllowsConfiguredOriginOnStreamingResponse(): void
    {
        $headers = new CorsHeaders(new Settings([
            'security' => [
                'cors' => ['allowed_origins' => ['https://host.test']],
            ],
        ]));
        $request = new ServerRequestFactory()
            ->createServerRequest('GET', 'https://claire.test/brain/stream')
            ->withHeader('Origin', 'https://host.test');

        $response = $headers->apply($request, new Response());

        $this->assertSame(
            'https://host.test',
            $response->getHeaderLine('Access-Control-Allow-Origin')
        );
        $this->assertSame(
            'true',
            $response->getHeaderLine('Access-Control-Allow-Credentials')
        );
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testApplyRejectsUnknownOrigin(): void
    {
        $headers = new CorsHeaders(new Settings([
            'security' => [
                'cors' => ['allowed_origins' => ['https://host.test']],
            ],
        ]));
        $request = new ServerRequestFactory()
            ->createServerRequest('GET', 'https://claire.test/brain/stream')
            ->withHeader('Origin', 'https://attacker.test');

        $response = $headers->apply($request, new Response());

        $this->assertSame(
            '',
            $response->getHeaderLine('Access-Control-Allow-Origin')
        );
    }
}
