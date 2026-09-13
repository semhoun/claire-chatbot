<?php

declare(strict_types=1);

use App\Controller\SseInternalController;
use App\Middleware\SseInternalMiddleware;
use App\Services\Settings;
use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;

$rootPath = dirname(__DIR__);
require_once $rootPath . '/vendor/autoload.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

// Fail closed even if a public PHP/static-file rule accidentally reaches this script.
if (! SseInternalMiddleware::isPrivateListener($_SERVER)) {
    http_response_code(404);
    exit;
}

$settings = Settings::load();
$boundary = new SseInternalMiddleware($settings);
$builder = new ContainerBuilder();
$builder->addDefinitions($rootPath . '/config/dependencies.php');
$builder->addDefinitions([Settings::class => $settings]);
$app = Bridge::create($builder->build());
foreach (['/open', '/snapshot', '/close'] as $path) {
    $app->post($path, SseInternalController::class);
}
$app->addRoutingMiddleware();
$app->add($boundary);
$app->run();
