<?php

declare(strict_types=1);

use App\Controller\HistoryController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (App $app): void {
    $app->group('/history', static function (Group $group): void {
        $group->get('/count', [HistoryController::class, 'count'])->setName('history.count');
        $group->get('/list', [HistoryController::class, 'list'])->setName('history.list');
        $group->get('/open/{threadId}', [HistoryController::class, 'open'])->setName('history.open');
        $group->post('/new', [HistoryController::class, 'create'])->setName('history.new');
        $group->delete('/delete/{threadId}', [HistoryController::class, 'delete'])->setName('history.delete');
    });
};
