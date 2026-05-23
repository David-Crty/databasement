<?php

namespace App\Notifications;

use App\Models\Snapshot;

class BackupSuccessNotification extends BaseSuccessNotification
{
    public function __construct(
        public Snapshot $snapshot
    ) {}

    public function getMessage(): SuccessNotificationMessage
    {
        return $this->message(
            title: '✅ Backup Succeeded: '.$this->snapshot->databaseServer->name,
            body: 'A backup job completed successfully.',
            actionText: '🔗 View Job Details',
            actionUrl: route('snapshots.index', ['job' => $this->snapshot->backup_job_id]),
            footerText: '🕐 '.\App\Support\Formatters::humanDate(now()),
            fields: [
                'Server' => $this->snapshot->databaseServer->name,
                'Database' => $this->snapshot->database_name ?? 'Unknown',
            ],
        );
    }
}
