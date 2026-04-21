<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'defaultQueue' => 'shared',
    'expireAfter' => 3600,
    'worker' => [
        'timeout' => Env::get('QUEUE_WORKER_TIMEOUT', 5),
        'maxJobs' => Env::get('QUEUE_WORKER_MAX_JOBS', 256),
        'maxTime' => Env::get('QUEUE_WORKER_MAX_TIME', 3600),
    ],
];
