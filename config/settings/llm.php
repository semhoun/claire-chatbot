<?php

declare(strict_types=1);

return [
    'openai' => [
        'key' => getenv('OPENAPI_KEY', true),
        'baseUri' => getenv('OPENAPI_URL', true),
        'model' => getenv('OPENAPI_MODEL', true),
    ],
    'tools' => [
        'searchXngGUrl' => getenv('SEARXNG_URL', true),
    ],
];
