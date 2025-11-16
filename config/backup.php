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
];
