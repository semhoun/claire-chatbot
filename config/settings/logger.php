<?php

declare(strict_types=1);

use Monolog\Level;

return [
    'name' => _env('OTEL_SERVICE_NAME', 'claire'),
    'level' => _env('DEBUG_MODE', false) ? Level::Debug : Level::Info,
];
