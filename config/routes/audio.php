<?php

declare(strict_types=1);

use App\Controller\AudioController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (App $app): void {
    $app->group('/v1/audio', static function (Group $group): void {
        $group->post('/transcriptions', [AudioController::class, 'transcriptions'])
            ->setName('audio.transcriptions');
        $group->post('/speech', [AudioController::class, 'speech'])
            ->setName('audio.speech');
    });
};
