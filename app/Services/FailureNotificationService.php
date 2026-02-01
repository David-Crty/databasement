<?php

namespace App\Services;

use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
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

    private function send(\Illuminate\Notifications\Notification $notification): void
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
    private function getNotificationRoutes(): array
    {
        $enabledChannels = explode(',', (string) config('notifications.channels', 'mail'));
        $enabledChannels = array_map('trim', $enabledChannels);

        $channelConfigs = [
            'mail' => config('notifications.mail.to'),
            'slack' => config('notifications.slack.webhook_url'),
            'discord' => config('notifications.discord.channel_id'),
        ];

        $routes = [];
        foreach ($channelConfigs as $channel => $value) {
            if (in_array($channel, $enabledChannels) && $value) {
                $routes[$channel] = $value;
            }
        }

        return $routes;
    }
}
