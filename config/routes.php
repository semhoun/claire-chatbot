<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Services\Settings;
use Slim\App;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

return static function (App $app): void {
    $app->get('/health', HealthController::class)->setName('health');

    // Activating all routes
    foreach (glob(Settings::getAppRoot() . '/config/routes/*.php') as $file) {
        $route = require $file;
        $route($app);
    }

    // OPTIONS
    $app->map(['OPTIONS'], '/{routes:.*}', static fn (Request $request, Response $response, $args): Response => $response->withStatus(204));

    // Not Found
    $app->map(
        ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
        '/{routes:.*}',
        static function (Request $request): void {
            throw new Slim\Exception\HttpNotFoundException($request);
        }
    );
};
