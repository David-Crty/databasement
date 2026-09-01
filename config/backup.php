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
    | PostgreSQL Client Binary Directory
    |--------------------------------------------------------------------------
    |
    | Optional absolute path to the directory containing psql/pg_dump/pg_restore.
    | Leave unset to invoke them by bare name and rely on $PATH (the default).
    |
    | On hosts with several PostgreSQL client versions installed side by side
    | (e.g. native installs from the PGDG yum/apt repos), psql can intermittently
    | fail with "could not find own program executable" while resolving itself
    | via $PATH. Pinning an explicit directory here makes every invocation use
    | one unambiguous absolute path instead.
    |
    */

    'postgresql_client_bin_dir' => env('POSTGRES_CLIENT_BIN_DIR'),
];
