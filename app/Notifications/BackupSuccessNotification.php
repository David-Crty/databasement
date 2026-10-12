<?php

namespace App\Notifications;

use App\Models\Snapshot;
use App\Notifications\Concerns\DescribesSnapshot;

class BackupSuccessNotification extends BaseSuccessNotification
{
    use DescribesSnapshot;

    public function __construct(
        public Snapshot $snapshot
    ) {}

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: '✅ '.__('Backup Succeeded: :server', ['server' => $this->snapshot->databaseServer->name]),
            body: __('A backup job completed successfully.'),
            actionUrl: $this->snapshotUrl(),
            fields: $this->snapshotFields(),
        );
    }
}
