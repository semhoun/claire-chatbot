<?php

declare(strict_types=1);

use Monolog\Level;

return [
    'name' => env('OTEL_SERVICE_NAME', 'claire'),
    'level' => env('DEBUG_MODE', false) ? Level::Debug : Level::Info,
];
