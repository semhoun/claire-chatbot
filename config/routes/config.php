<?php

declare(strict_types=1);

use App\Controller\ConfigController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (
    App $app,
): void {
    $app->group('/config', static function (Group $group): void {
        $group->post('/chat_mode', [ConfigController::class, 'chatMode'])->setName('config.mode');
        $group->post('/layout_mode', [ConfigController::class, 'layoutMode'])->setName('config.layout');
        $group->post('/brain_avatar', [ConfigController::class, 'brainAvatar'])->setName('config.brain_avatar');
    });
};
