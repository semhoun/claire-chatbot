<?php

declare(strict_types=1);

use App\Services\Env;
use Monolog\Level;

return [
    'name' => Env::get('OTEL_SERVICE_NAME', 'claire'),
    'level' => Env::get('DEBUG_MODE', false) ? Level::Debug : Level::Info,
];
