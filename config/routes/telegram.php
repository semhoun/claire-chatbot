<?php

declare(strict_types=1);

use App\Controller\TelegramController;
use App\Controller\TelegramWebAppController;
use Slim\App;

return static function (App $app): void {
    // Telegram webhook
    $app->post('/telegram/webhook', [TelegramController::class, 'webhook'])->setName('telegram_webhook');

    // Telegram WebApp
    $app->get('/telegram/webapp', [TelegramWebAppController::class, 'index'])->setName('telegram_webapp');
    $app->post('/telegram/webapp/api', [TelegramWebAppController::class, 'api']);
};
