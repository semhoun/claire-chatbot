<?php

declare(strict_types=1);

use App\Middleware\AuthMiddleware;
use App\Middleware\BaseUrlMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\JwtSessionMiddleware;
use App\Renderer\HtmlErrorRenderer;
use App\Renderer\JsonErrorRenderer;
use App\Services\Settings;
use RKA\Middleware\ProxyDetection;
use Slim\App;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

return static function (App $app): void {
    $container = $app->getContainer();
    $settings = $container->get(Settings::class);

    // Must be first because called in reverse order, so Auth must be the last called
    $app->add(AuthMiddleware::class);

    $app->add(JwtSessionMiddleware::class);
    $app->add(TwigMiddleware::create($app, $container->get(Twig::class)));
    $app->add(BaseUrlMiddleware::class);
    $app->add(new ProxyDetection());
    $app->add(CorsMiddleware::class);

    // Telegram webhook needs to bypass some middlewares if they are too restrictive (like OIDC)
    // But usually OIDC is only on specific routes or handled via session

    // Add error handling middleware.
    if ($settings->get('debug')) {
        $errorMiddleware = $app->addErrorMiddleware(true, true, true);
    } else {
        $errorMiddleware = $app->addErrorMiddleware(false, true, true);
    }

    $errorHandler = $errorMiddleware->getDefaultErrorHandler();
    $errorHandler->registerErrorRenderer('text/html', HtmlErrorRenderer::class);
    $errorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);
    $errorHandler->setDefaultErrorRenderer('application/json', JsonErrorRenderer::class);
};
