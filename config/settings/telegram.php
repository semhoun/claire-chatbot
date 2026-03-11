<?php

declare(strict_types=1);

return [
    'bot_token' => _env('TELEGRAM_BOT_TOKEN'),
    'webhook' => [
        'enabled' => _env('TELEGRAM_WEBHOOK_ENABLED', true),
        'url' => _env('TELEGRAM_WEBHOOK_URL'),
        'secret_token' => _env('TELEGRAM_WEBHOOK_SECRET'),
    ],
    'daemon' => [
        'enabled' => _env('TELEGRAM_DAEMON_ENABLED', false),
        'polling_interval' => (int) _env('TELEGRAM_POLLING_INTERVAL', 1),
        'timeout' => (int) _env('TELEGRAM_POLLING_TIMEOUT', 30),
    ],
];
