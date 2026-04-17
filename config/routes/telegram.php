<?php

declare(strict_types=1);

use App\Controller\TelegramController;
use App\Controller\TelegramWebAppController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (App $app): void {
    $app->group('/telegram', static function (Group $group): void {
        $group->post('/webhook', [TelegramController::class, 'webhook'])->setName('telegram.webhook');
        $group->get('/webapp', [TelegramWebAppController::class, 'index'])->setName('telegram.webapp');
        $group->post('/webapp/api', [TelegramWebAppController::class, 'api'])->setName('telegram.webapp.api');
    });
};
