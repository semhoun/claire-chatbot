<?php

declare(strict_types=1);

use App\Controller\FileController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (App $app): void {
    $app->group('/files', static function (Group $group): void {
        $group->get('/count', [FileController::class, 'count'])->setName('files.count');
        $group->get('/list', [FileController::class, 'list'])->setName('files.list');
        $group->get('/by-token/{token}', [FileController::class, 'downloadByToken'])->setName('files.by_token');
        $group->post('/upload', [FileController::class, 'upload'])->setName('files.upload');
        $group->post('/upload_rag', [FileController::class, 'uploadRag'])->setName('files.upload_rag');
        $group->delete('/delete/{id}', [FileController::class, 'delete'])->setName('files.delete');
    });
};
