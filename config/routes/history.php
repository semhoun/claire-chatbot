<?php

declare(strict_types=1);

use App\Controller\HistoryController;
use Slim\App;

return static function (App $app): void {
    $app->get('/history/count', [HistoryController::class, 'count'])->setName('history.count');
    $app->get('/history/list', [HistoryController::class, 'list'])->setName('history.list');
    $app->get('/history/open/{threadId}', [HistoryController::class, 'open'])->setName('history.open');
    $app->post('/history/new', [HistoryController::class, 'create'])->setName('history.new');
    $app->delete('/history/delete/{threadId}', [HistoryController::class, 'delete'])->setName('history.delete');
};
