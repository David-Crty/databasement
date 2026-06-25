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
            title: '✅ '.__('Restore Succeeded: :server', ['server' => $this->restore->targetServer->name ?? __('Unknown')]),
            body: __('A restore job completed successfully.'),
            actionUrl: route('restores.index', ['job' => $this->restore->backup_job_id]),
            fields: [
                __('Target Server') => $this->restore->targetServer->name ?? __('Unknown'),
                __('Target Database') => $this->restore->schema_name ?? __('Unknown'),
                __('Source Snapshot') => $this->restore->snapshot->filename ?? __('Unknown'),
            ],
        );
    }
}
