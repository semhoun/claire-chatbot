<?php

declare(strict_types=1);

use App\Controller\BrainController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (
    App $app,
): void {
    $app->group('/brain', static function (Group $group): void {
        $group->post('/messages', [BrainController::class, 'submitMessage'])->setName('brain.messages');
        $group->post('/audio', [BrainController::class, 'generateAudio'])->setName('brain.audio');
        $group->get('/stream', [BrainController::class, 'stream'])->setName('brain.stream');
    });
};
