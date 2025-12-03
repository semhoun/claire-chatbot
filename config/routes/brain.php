<?php

declare(strict_types=1);

use App\Controller\BrainController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (
    App $app,
): void {
    $app->group('/brain', static function (Group $group): void {
        $group->map(['POST', 'GET'], '/chat', [BrainController::class, 'chat'])->setName('brain.chat');
        $group->map(['POST', 'GET'], '/stream', [BrainController::class, 'stream'])->setName('brain.stream');
    });
};
