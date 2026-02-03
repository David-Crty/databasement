<?php

namespace App\Services;

use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use App\Notifications\BaseFailedNotification;
use App\Notifications\RestoreFailedNotification;
use Illuminate\Support\Facades\Notification;

class FailureNotificationService
{
    public function notifyBackupFailed(Snapshot $snapshot, \Throwable $exception): void
    {
        $this->send(new BackupFailedNotification($snapshot, $exception));
    }

    public function notifyRestoreFailed(Restore $restore, \Throwable $exception): void
    {
        $this->send(new RestoreFailedNotification($restore, $exception));
    }

    private function send(BaseFailedNotification $notification): void
    {
        if (! config('notifications.enabled')) {
            return;
        }

        $routes = $this->getNotificationRoutes();

        if (empty($routes)) {
            return;
        }

        Notification::routes($routes)->notify($notification);
    }

    /**
     * @return array<string, string>
     */
    public function getNotificationRoutes(): array
    {
        return array_filter([
            'mail' => config('notifications.mail.to'),
            'slack' => config('notifications.slack.webhook_url'),
            'discord' => config('notifications.discord.channel_id'),
        ]);
    }
}
