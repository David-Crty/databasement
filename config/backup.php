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
    | Default: Backup Ownership & Privilege Information (PostgreSQL)
    |--------------------------------------------------------------------------
    |
    | Seeds the "Backup ownership and privilege information" checkbox when
    | creating a new PostgreSQL server. Existing servers are never changed by
    | this setting; it only affects the form default, which admins can still
    | toggle per server.
    |
    */

    'default_dump_privileges' => env('BACKUP_DEFAULT_DUMP_PRIVILEGES', false),
];
