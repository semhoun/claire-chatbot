<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Controller\HealthController;
use App\Renderer\JsonRenderer;
use App\Services\Settings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;

#[AllowMockObjectsWithoutExpectations]
final class HealthTest extends TestCase
{
    public function testStatusReturnsCorrectData(): void
    {
        $settings = new Settings(['version' => '1.2.3']);
        $jsonRenderer = new JsonRenderer();
        $controller = new HealthController($jsonRenderer, $settings);
        $request = $this->createMock(ServerRequestInterface::class);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller($request, $response);
        $status = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('version', $status);
        $this->assertArrayHasKey('date', $status);
        $this->assertSame('1.2.3', $status['version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $status['date']
        );
    }
}
