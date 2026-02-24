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

        $this->refreshChannelServiceConfigs($routes);

        Notification::routes($routes)->notify($notification);
    }

    /**
     * Refresh third-party service configs from AppConfig before sending.
     *
     * This ensures tokens are fresh when channel classes are resolved from the
     * container — critical for Octane, where boot-time config may be stale
     * (e.g. Pushover's token is baked in at construction time).
     *
     * @param  array<string, string>  $routes
     */
    private function refreshChannelServiceConfigs(array $routes): void
    {
        $tokenMap = [
            'discord' => ['notifications.discord.token' => 'services.discord.token'],
            'telegram' => ['notifications.telegram.bot_token' => 'services.telegram-bot-api.token'],
            'pushover' => ['notifications.pushover.token' => 'services.pushover.token'],
        ];

        foreach ($tokenMap as $routeKey => $mappings) {
            if (! isset($routes[$routeKey])) {
                continue;
            }

            foreach ($mappings as $appConfigKey => $servicesConfigKey) {
                config([$servicesConfigKey => AppConfig::get($appConfigKey)]);
            }
        }
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
