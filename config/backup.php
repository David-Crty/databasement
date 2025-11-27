<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Filesystems
    |--------------------------------------------------------------------------
    |
    | Configuration for backup storage locations. The 'local' filesystem
    | is used for temporary backup files before transfer to remote storage.
    |
    */

    'filesystems' => [
        'local' => [
            'type' => 'local',
            'root' => env('BACKUP_LOCAL_ROOT', '/tmp/backups'),
        ],

        's3' => [
            'type' => 's3',
            'root' => env('BACKUP_S3_ROOT', '/backups'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MySQL CLI Type
    |--------------------------------------------------------------------------
    |
    | The type of MySQL CLI to use for backup and restore operations.
    | Options: 'mariadb' (default) or 'mysql'
    |
    */

    'mysql_cli_type' => env('MYSQL_CLI_TYPE', 'mariadb'),

    /*
    |--------------------------------------------------------------------------
    | End-to-End Test Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for E2E backup and restore tests with real databases.
    |
    */

    'e2e' => [
        'mysql' => [
            'host' => env('E2E_MYSQL_HOST', 'mysql'),
            'port' => env('E2E_MYSQL_PORT', 3306),
            'username' => env('E2E_MYSQL_USERNAME', 'root'),
            'password' => env('E2E_MYSQL_PASSWORD', 'admin'),
            'database' => env('E2E_MYSQL_DATABASE', 'testdb'),
        ],

        'postgres' => [
            'host' => env('E2E_POSTGRES_HOST', 'postgres'),
            'port' => env('E2E_POSTGRES_PORT', 5432),
            'username' => env('E2E_POSTGRES_USERNAME', 'admin'),
            'password' => env('E2E_POSTGRES_PASSWORD', 'admin'),
            'database' => env('E2E_POSTGRES_DATABASE', 'testdb'),
        ],
    ],
];
