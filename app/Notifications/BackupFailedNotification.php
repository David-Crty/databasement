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

    public function getMessage(): FailedNotificationMessage
    {
        return $this->message(
            title: 'Backup Failed: '.($this->snapshot->databaseServer->name ?? 'Unknown'),
            body: 'A backup job has failed and requires your attention.',
            actionText: 'View Job Details',
            actionUrl: url('/backup-jobs?job='.$this->snapshot->backup_job_id),
            footerText: now()->toDateTimeString(),
            fields: [
                'Server' => $this->snapshot->databaseServer->name ?? 'Unknown',
                'Database' => $this->snapshot->database_name ?? 'Unknown',
            ],
        );
    }
}
