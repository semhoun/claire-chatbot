<?php

declare(strict_types=1);

use App\Services\Settings;

return [
    'template_path' => Settings::getAppRoot() . '/tmpl',
    'config' => [
        'cache' => Settings::getAppRoot() . '/var/cache/twig',
        'debug' => true,
        'auto_reload' => _env('DEBUG_MODE', false),
    ],
];
