<?php

declare(strict_types=1);

use App\Middleware\BaseUrlMiddleware;
use Odan\Session\Middleware\SessionStartMiddleware;
use App\Renderer\HtmlErrorRenderer;
use App\Renderer\JsonErrorRenderer;
use App\Services\Settings;
use RKA\Middleware\ProxyDetection;
use Slim\App;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Tracy\Debugger;

return static function (App $app): void {
    $container = $app->getContainer();
    $settings = $container->get(Settings::class);

    $app->add(TwigMiddleware::create($app, $container->get(Twig::class)));
    $app->add(SessionStartMiddleware::class);
    $app->add($container->get(BaseUrlMiddleware::class));

    $app->add(new ProxyDetection());

    // Add error handling middleware.
    if ($settings->get('debug')) {
        $app->add(new SlimTracy\Middlewares\TracyMiddleware($app, $settings->get('tracy')));
        Debugger::enable(Debugger::Development);
    }
    $errorMiddleware = $app->addErrorMiddleware($settings->get('debug'), true, true);
    $errorHandler = $errorMiddleware->getDefaultErrorHandler();
    $errorHandler->registerErrorRenderer('text/html', HtmlErrorRenderer::class);
    $errorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);
    $errorHandler->setDefaultErrorRenderer('application/json', JsonErrorRenderer::class);
};
