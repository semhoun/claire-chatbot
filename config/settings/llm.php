<?php

declare(strict_types=1);

use App\Services\Settings;

// Mandatory environment variables
env_required([
    'OPENAPI_URL',
    'OPENAPI_MODEL',
]);

return [
    'openai' => [
        'key' => _env('OPENAPI_KEY'),
        'baseUri' => _env('OPENAPI_URL'),
        'model' => _env('OPENAPI_MODEL'),
        'modelSummary' => _env('OPENAPI_MODEL_SUMMARY') ?? _env('OPENAPI_MODEL'),
        'modelEmbed' => _env('OPENAPI_MODEL_EMBED'),
    ],
    'history' => [
        'contextWindow' => 5000000, //50000
    ],
    'tools' => [
        'searchXngUrl' => _env('SEARXNG_URL'),
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
    'defaultBrain' => 'claire',
    'rawMimeTypes' => [
        'application/x-csh',
        'text/css',
        'text/csv',
        'text/html',
        'text/calendar',
        'application/javascript',
        'application/json',
        'application/jsonl',
        'application/x-sh',
        'image/svg+xml',
        'application/typescript',
        'application/xhtml+xml',
        'application/xml',
    ],
    'httpClient' => [
        'timeout' => 300.0,
        'connectTimeout' => 10.0,
    ],
];
