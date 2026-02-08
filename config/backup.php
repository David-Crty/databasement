<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | The encryption key used when BACKUP_COMPRESSION=encrypted.
    | Defaults to APP_KEY. Used with 7-Zip AES-256 encryption.
    |
    | WARNING: If you change this key, you will not be able to restore
    | backups that were encrypted with the previous key.
    |
    */

    'encryption_key' => env('BACKUP_ENCRYPTION_KEY', env('APP_KEY')),

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
