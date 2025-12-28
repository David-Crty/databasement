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
];
