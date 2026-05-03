<?php

declare(strict_types=1);

use App\Controller\HomeController;
use App\Controller\EmbedController;
use Slim\App;

return static function (
    App $app,
): void {
    $app->get('/', [HomeController::class, 'index'])->setName('home');
    $app->get('/embed', [EmbedController::class, 'index'])->setName('embed');
};
