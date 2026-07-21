<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Snapshot;
use App\Notifications\Concerns\HasChannelRouting;
use App\Support\Formatters;
use Illuminate\Notifications\Notification;

/**
 * Sent when a backup exceeded its volume's storage limit but the volume is in
 * notify-only mode, so the backup was uploaded anyway. Delivered to every
 * configured channel because a volume is an installation-wide resource.
 */
class StorageLimitWarningNotification extends Notification
{
    use HasChannelRouting;

    public function __construct(
        public Snapshot $snapshot,
        public string $warning,
    ) {}

    public function getMessage(): NotificationMessage
    {
        return new NotificationMessage(
            type: NotificationType::Failure,
            title: '⚠️ '.__('Storage limit reached on volume: :volume', ['volume' => $this->snapshot->volume->name]),
            body: __('A backup of ":server" on volume ":volume" exceeded its storage limit. It was still uploaded because the volume is set to notify only: free up space to stay within the limit.', [
                'server' => $this->snapshot->databaseServer->name,
                'volume' => $this->snapshot->volume->name,
            ]),
            actionText: '🔗 '.__('View Job Details'),
            actionUrl: route('snapshots.index', ['job' => $this->snapshot->backup_job_id]),
            footerText: '🕐 '.Formatters::humanDate(now()),
            fields: [
                __('Server') => $this->snapshot->databaseServer->name,
                __('Database') => $this->snapshot->database_name ?? __('Unknown'),
                __('Volume') => $this->snapshot->volume->name,
            ],
            errorMessage: $this->warning,
            errorLabel: '⚠️ '.__('Storage Details'),
        );
    }
}
