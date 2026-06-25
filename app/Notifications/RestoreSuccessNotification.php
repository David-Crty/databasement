<?php

namespace App\Notifications;

use App\Models\Restore;

class RestoreSuccessNotification extends BaseSuccessNotification
{
    public function __construct(
        public Restore $restore
    ) {}

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: '✅ Restore Succeeded: '.($this->restore->targetServer->name ?? 'Unknown'),
            body: 'A restore job completed successfully.',
            actionUrl: route('restores.index', ['job' => $this->restore->backup_job_id]),
            fields: [
                'Target Server' => $this->restore->targetServer->name ?? 'Unknown',
                'Target Database' => $this->restore->schema_name ?? 'Unknown',
                'Source Snapshot' => $this->restore->snapshot->filename ?? 'Unknown',
            ],
        );
    }
}
