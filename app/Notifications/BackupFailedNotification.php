<?php

namespace App\Notifications;

use App\Models\Snapshot;
use App\Notifications\Concerns\DescribesSnapshot;

class BackupFailedNotification extends BaseFailedNotification
{
    use DescribesSnapshot;

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
            actionUrl: $this->snapshotUrl(),
            fields: $this->snapshotFields(),
        );
    }
}
