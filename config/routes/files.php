<?php

declare(strict_types=1);

use App\Controller\FileController;
use Slim\App;

return static function (App $app): void {
    $app->get('/files/count', [FileController::class, 'count'])->setName('files.count');
    $app->get('/files/list', [FileController::class, 'list'])->setName('files.list');
    $app->get('/files/by-token/{token}', [FileController::class, 'downloadByToken'])->setName('files.by_token');
    $app->post('/files/upload', [FileController::class, 'upload'])->setName('files.upload');
    $app->delete('/files/delete/{id}', [FileController::class, 'delete'])->setName('files.delete');
};
