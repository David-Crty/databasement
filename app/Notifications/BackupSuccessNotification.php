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
            title: '✅ '.__('Backup Succeeded: :server', ['server' => $this->snapshot->databaseServer->name]),
            body: __('A backup job completed successfully.'),
            actionUrl: route('snapshots.index', ['job' => $this->snapshot->backup_job_id]),
            fields: [
                __('Server') => $this->snapshot->databaseServer->name,
                __('Database') => $this->snapshot->database_name ?? __('Unknown'),
            ],
        );
    }
}
