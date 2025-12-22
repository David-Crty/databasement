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

    'tmp_folder' => env('BACKUP_TMP_FOLDER', '/tmp/backups'),

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

    'backup_test' => [
        'mysql' => [
            'host' => env('BACKUP_TEST_MYSQL_HOST', 'mysql'),
            'port' => env('BACKUP_TEST_MYSQL_PORT', 3306),
            'username' => env('BACKUP_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('BACKUP_TEST_MYSQL_PASSWORD', 'root'),
            'database' => env('BACKUP_TEST_MYSQL_DATABASE', 'testdb'),
        ],

        'postgres' => [
            'host' => env('BACKUP_TEST_POSTGRES_HOST', 'postgres'),
            'port' => env('BACKUP_TEST_POSTGRES_PORT', 5432),
            'username' => env('BACKUP_TEST_POSTGRES_USERNAME', 'root'),
            'password' => env('BACKUP_TEST_POSTGRES_PASSWORD', 'root'),
            'database' => env('BACKUP_TEST_POSTGRES_DATABASE', 'testdb'),
        ],
    ],
];
