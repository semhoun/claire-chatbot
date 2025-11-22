<?php

declare(strict_types=1);

use App\Services\Settings;

return [
    // Driver: sqlite, mysql, postgres
    'driver' => getenv('DATABASE_KIND', true),

    // Used for mysql, postgres, mariadb
    'host' => getenv('DATABASE_HOST', true),
    'port' => getenv('DATABASE_PORT', true),
    'dbname' => getenv('DATABASE_NAME', true),
    'user' => getenv('DATABASE_USER', true),
    'password' => getenv('DATABASE_PASSWORD', true),

    // Used only for sqlite
    'path' => Settings::getAppRoot() . '/var/database.sqlite',

    'doctrine' => [
        'entity_path' => [Settings::getAppRoot() . '/src/Entity'],
        'migrations' => [
            'table_storage' => [
                'table_name' => 'db_version',
                'version_column_name' => 'version',
                'version_column_length' => 1024,
                'executed_at_column_name' => 'executed_at',
                'execution_time_column_name' => 'execution_time',
            ],
            'migrations_paths' => [
                'app' => Settings::getAppRoot() . '/migrations',
            ],
            'all_or_nothing' => true,
            'transactional' => true,
            'check_database_platform' => true,
            'organize_migrations' => 'none',
            'connection' => null,
            'em' => null,
            'custom_template' => Settings::getAppRoot()
                . '/migrations/doctrine_migrations_class.php.tpl',
        ],
    ],
];
