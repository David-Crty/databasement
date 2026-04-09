<?php

namespace App\Notifications;

use App\Models\Restore;

class RestoreSuccessNotification extends BaseSuccessNotification
{
    public function __construct(
        public Restore $restore
    ) {}

    public function getMessage(): SuccessNotificationMessage
    {
        return $this->message(
            title: '✅ Restore Succeeded: '.($this->restore->targetServer->name ?? 'Unknown'),
            body: 'A restore job completed successfully.',
            actionText: '🔗 View Job Details',
            actionUrl: route('jobs.index', ['job' => $this->restore->backup_job_id]),
            footerText: '🕐 '.now()->toDateTimeString(),
            fields: [
                'Target Server' => $this->restore->targetServer->name ?? 'Unknown',
                'Target Database' => $this->restore->schema_name ?? 'Unknown',
                'Source Snapshot' => $this->restore->snapshot->filename ?? 'Unknown',
            ],
        );
    }
}
