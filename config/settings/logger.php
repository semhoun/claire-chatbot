<?php

declare(strict_types=1);

use Monolog\Level;

$debug = getenv('DEBUG_MODE', true) === 'true';

return [
    'name' => getenv('OTEL_SERVICE_NAME', false),
    'level' => $debug ? Level::Debug : Level::Info,
];
