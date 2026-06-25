<?php

namespace App\Notifications;

use App\Models\Snapshot;

class BackupFailedNotification extends BaseFailedNotification
{
    public function __construct(
        public Snapshot $snapshot,
        \Throwable $exception
    ) {
        parent::__construct($exception);
    }

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: '🚨 Backup Failed: '.$this->snapshot->databaseServer->name,
            body: 'A backup job has failed and requires your attention.',
            actionUrl: route('snapshots.index', ['job' => $this->snapshot->backup_job_id]),
            fields: [
                'Server' => $this->snapshot->databaseServer->name,
                'Database' => $this->snapshot->database_name ?? 'Unknown',
            ],
        );
    }
}
