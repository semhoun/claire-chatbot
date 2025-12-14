<?php

declare(strict_types=1);

use App\Services\Settings;

return [
    'openai' => [
        'key' => getenv('OPENAPI_KEY', true),
        'baseUri' => getenv('OPENAPI_URL', true),
        'model' => getenv('OPENAPI_MODEL', true),
        'modelSummary' => getenv('OPENAPI_MODEL_SUMMARY', true) ?? getenv('OPENAPI_MODEL', true),
        'modelEmbed' => getenv('OPENAPI_MODEL_EMBED', true),
    ],
    'history' => [
        'contextWindow' => 5000000, //50000
    ],
    'tools' => [
        'searchXngUrl' => getenv('SEARXNG_URL', true),
    ],
    'rag' => [
        'type' => 'file', // Could be 'file'

        // Used only for file
        'path' => Settings::getAppRoot() . '/var/',
    ],
    // Liste des assistants disponibles (slug => FQCN)
    'brains' => [
        'claire' => App\Brain\Claire::class,
        'einstein' => App\Brain\Einstein::class,
        'flashy' => App\Brain\Flashy::class,
    ],
];
