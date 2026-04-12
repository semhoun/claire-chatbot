<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'bot_token' => Env::get('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => Env::get('TELEGRAM_WEBHOOK_SECRET'),
];
