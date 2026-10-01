<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'sql' => [
        'enabled' => filter_var(Env::get('SQL_OBSERVABILITY_ENABLED', false), FILTER_VALIDATE_BOOL),
        'maxQueryTextBytes' => max(1, (int) Env::get('SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES', 16384)),
    ],
];
