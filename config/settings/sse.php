<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'queue_ttl' => (int) Env::get('SSE_QUEUE_TTL', 60),
    'pop_timeout' => (int) Env::get('SSE_POP_TIMEOUT', 15),
    'stream_token_ttl' => (int) Env::get('SSE_STREAM_TOKEN_TTL', 30),
];
