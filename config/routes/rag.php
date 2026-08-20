<?php

declare(strict_types=1);

use App\Controller\RagController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return static function (App $app): void {
    $app->group('/rag', static function (Group $group): void {
        $group->get('/list', [RagController::class, 'list'])->setName('rag.list');
        $group->get('/count', [RagController::class, 'count'])->setName('rag.count');
        $group->get('/segments/{id}', [RagController::class, 'segments'])->setName('rag.segments');
        $group->post('/upload', [RagController::class, 'upload'])->setName('rag.upload');
        $group->post('/text', [RagController::class, 'addText'])->setName('rag.text');
        $group->post('/url', [RagController::class, 'addUrl'])->setName('rag.url');
        $group->post('/toggle/{id}', [RagController::class, 'toggle'])->setName('rag.toggle');
        $group->delete('/delete/{id}', [RagController::class, 'delete'])->setName('rag.delete');
    });
};
