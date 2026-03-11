<?php

declare(strict_types=1);

use App\Controller\TelegramConfigController;
use App\Controller\TelegramController;
use Slim\App;

return static function (App $app): void {
    // Telegram webhook
    $app->post('/webhook/telegram', [TelegramController::class, 'webhook'])->setName('telegram_webhook');
};
