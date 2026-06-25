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
            title: '🚨 Restore Failed: '.($this->restore->targetServer->name ?? 'Unknown'),
            body: 'A restore job has failed and requires your attention.',
            actionUrl: route('restores.index', ['job' => $this->restore->backup_job_id]),
            fields: [
                'Target Server' => $this->restore->targetServer->name ?? 'Unknown',
                'Target Database' => $this->restore->schema_name ?? 'Unknown',
                'Source Snapshot' => $this->restore->snapshot->filename ?? 'Unknown',
            ],
        );
    }
}
