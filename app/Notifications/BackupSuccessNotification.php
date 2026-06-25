<?php

namespace App\Notifications;

use App\Models\Snapshot;

class BackupSuccessNotification extends BaseSuccessNotification
{
    public function __construct(
        public Snapshot $snapshot
    ) {}

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: '✅ Backup Succeeded: '.$this->snapshot->databaseServer->name,
            body: 'A backup job completed successfully.',
            actionUrl: route('snapshots.index', ['job' => $this->snapshot->backup_job_id]),
            fields: [
                'Server' => $this->snapshot->databaseServer->name,
                'Database' => $this->snapshot->database_name ?? 'Unknown',
            ],
        );
    }
}
