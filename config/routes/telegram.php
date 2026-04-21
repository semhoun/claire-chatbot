<?php

declare(strict_types=1);

use App\Controller\TelegramController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (App $app): void {
    $app->group('/telegram', static function (Group $group): void {
        $group->post('/webhook', [TelegramController::class, 'webhook'])->setName('telegram.webhook');
        $group->get('/webapp', [TelegramController::class, 'webAppIndex'])->setName('telegram.webapp.index');
        $group->post('/webapp/api', [TelegramController::class, 'api'])->setName('telegram.webapp.api');
    });
};
