<?php

namespace App\Services;

use App\Facades\AppConfig;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use App\Notifications\BaseFailedNotification;
use App\Notifications\RestoreFailedNotification;
use App\Notifications\SnapshotsMissingNotification;
use Illuminate\Support\Collection;
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

    /**
     * @param  Collection<int, array{server: string, database: string, filename: string}>  $missingSnapshots
     */
    public function notifySnapshotsMissing(Collection $missingSnapshots): void
    {
        $this->send(new SnapshotsMissingNotification($missingSnapshots));
    }

    private function send(BaseFailedNotification $notification): void
    {
        if (! AppConfig::get('notifications.enabled')) {
            return;
        }

        $routes = $this->getNotificationRoutes();

        if (empty($routes)) {
            return;
        }

        Notification::routes($routes)->notify($notification);
    }

    /**
     * Routes determine which channels are active (null values are filtered out).
     * For custom channels (gotify, webhook), the route value acts as an enabled flag —
     * the channel classes fetch their full config (tokens, secrets) from AppConfig directly.
     *
     * @return array<string, string>
     */
    public function getNotificationRoutes(): array
    {
        return array_filter([
            'mail' => AppConfig::get('notifications.mail.to'),
            'slack' => AppConfig::get('notifications.slack.webhook_url'),
            'discord' => AppConfig::get('notifications.discord.channel_id'),
            'telegram' => AppConfig::get('notifications.telegram.chat_id'),
            'pushover' => AppConfig::get('notifications.pushover.user_key'),
            'gotify' => AppConfig::get('notifications.gotify.url'),
            'webhook' => AppConfig::get('notifications.webhook.url'),
        ]);
    }
}
