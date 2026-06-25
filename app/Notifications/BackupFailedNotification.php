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
            title: '🚨 '.__('Backup Failed: :server', ['server' => $this->snapshot->databaseServer->name]),
            body: __('A backup job has failed and requires your attention.'),
            actionUrl: route('snapshots.index', ['job' => $this->snapshot->backup_job_id]),
            fields: [
                __('Server') => $this->snapshot->databaseServer->name,
                __('Database') => $this->snapshot->database_name ?? __('Unknown'),
            ],
        );
    }
}
