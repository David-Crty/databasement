<?php

namespace App\Services;

use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use App\Notifications\RestoreFailedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

class FailureNotificationService
{
    public function notifyBackupFailed(Snapshot $snapshot, \Throwable $exception): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $notifiable = $this->buildNotifiable();

        if ($notifiable === null) {
            return;
        }

        $notifiable->notify(new BackupFailedNotification($snapshot, $exception));
    }

    public function notifyRestoreFailed(Restore $restore, \Throwable $exception): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $notifiable = $this->buildNotifiable();

        if ($notifiable === null) {
            return;
        }

        $notifiable->notify(new RestoreFailedNotification($restore, $exception));
    }

    private function isEnabled(): bool
    {
        return (bool) config('notifications.enabled');
    }

    private function buildNotifiable(): ?AnonymousNotifiable
    {
        $routes = $this->getNotificationRoutes();

        if (empty($routes)) {
            return null;
        }

        return Notification::routes($routes);
    }

    /**
     * @return array<string, string>
     */
    private function getNotificationRoutes(): array
    {
        $channels = $this->getEnabledChannels();
        $routes = [];

        if (in_array('mail', $channels) && config('notifications.mail.to')) {
            $routes['mail'] = config('notifications.mail.to');
        }

        if (in_array('slack', $channels) && config('notifications.slack.webhook_url')) {
            $routes['slack'] = config('notifications.slack.webhook_url');
        }

        return $routes;
    }

    /**
     * @return array<int, string>
     */
    private function getEnabledChannels(): array
    {
        $channelsConfig = config('notifications.channels', 'mail');

        return array_map('trim', explode(',', $channelsConfig));
    }
}
