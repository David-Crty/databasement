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
            title: '🚨 Backup Failed: '.$this->snapshot->databaseServer->name,
            body: 'A backup job has failed and requires your attention.',
            actionText: '🔗 View Job Details',
            actionUrl: route('snapshots.index', ['job' => $this->snapshot->backup_job_id]),
            footerText: '🕐 '.\App\Support\Formatters::humanDate(now()),
            errorLabel: '❌ Error Details',
            fields: [
                'Server' => $this->snapshot->databaseServer->name,
                'Database' => $this->snapshot->database_name ?? 'Unknown',
            ],
        );
    }
}
