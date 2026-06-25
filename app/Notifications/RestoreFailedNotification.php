<?php

namespace App\Notifications;

use App\Models\Restore;

class RestoreFailedNotification extends BaseFailedNotification
{
    public function __construct(
        public Restore $restore,
        \Throwable $exception
    ) {
        parent::__construct($exception);
    }

    public function getMessage(): NotificationMessage
    {
        return $this->message(
            title: '🚨 '.__('Restore Failed: :server', ['server' => $this->restore->targetServer->name ?? __('Unknown')]),
            body: __('A restore job has failed and requires your attention.'),
            actionUrl: route('restores.index', ['job' => $this->restore->backup_job_id]),
            fields: [
                __('Target Server') => $this->restore->targetServer->name ?? __('Unknown'),
                __('Target Database') => $this->restore->schema_name ?? __('Unknown'),
                __('Source Snapshot') => $this->restore->snapshot->filename ?? __('Unknown'),
            ],
        );
    }
}
