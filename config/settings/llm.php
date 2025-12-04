<?php

declare(strict_types=1);

return [
    'openai' => [
        'key' => getenv('OPENAPI_KEY', true),
        'baseUri' => getenv('OPENAPI_URL', true),
        'model' => getenv('OPENAPI_MODEL', true),
        'modelSummary' => getenv('OPENAPI_MODEL_SUMMARY', true) ?? getenv('OPENAPI_MODEL', true),
        'modelEmbed' => getenv('OPENAPI_MODEL_EMBED', true),
    ],
    'tools' => [
        'searchXngUrl' => getenv('SEARXNG_URL', true),
    ],
    'brain' => [
        'systemPrompt' => 'You are a friendly AI Agent named Claire and created by Nathanaël SEMHOUN.',
    ],
    'summary' => [
        'systemPrompt' => 'Tu es un assistant qui génère un titre concis et un résumé bref pour une conversation. Règles: 1) Réponds exclusivement en JSON avec les clés "title" et "summary". 2) Le "title" en français, clair, <= 80 caractères, sans guillemets décoratifs. 3) Le "summary" en français, 1 à 3 phrases, <= 400 caractères, pas de balises Markdown. 4) Si le contenu est vide, mets title="Nouvelle conversation" et summary="".',
    ],
];
