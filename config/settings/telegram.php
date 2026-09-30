<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'bot_token' => Env::get('TELEGRAM_BOT_TOKEN'),
    'bot_username' => Env::get('TELEGRAM_BOT_USERNAME', ''),
    'webhook_secret' => Env::get('TELEGRAM_WEBHOOK_SECRET'),
];
