<?php

namespace App\Services;

use App\Enums\NotificationChannelType;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use App\Notifications\BackupSuccessNotification;
use App\Notifications\ChannelNotifiable;
use App\Notifications\RestoreFailedNotification;
use App\Notifications\RestoreSuccessNotification;
use App\Notifications\SnapshotsMissingNotification;
use App\Notifications\TestNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class NotificationService
{
    public function notifyBackupFailed(Snapshot $snapshot, \Throwable $exception): void
    {
        $server = $snapshot->databaseServer;

        if (! $server->shouldNotifyOn('failure')) {
            return;
        }

        $this->sendToChannels(
            new BackupFailedNotification($snapshot, $exception),
            $server->resolveNotificationChannels(),
        );
    }

    public function notifyBackupSuccess(Snapshot $snapshot): void
    {
        $server = $snapshot->databaseServer;

        if (! $server->shouldNotifyOn('success')) {
            return;
        }

        $this->sendToChannels(
            new BackupSuccessNotification($snapshot),
            $server->resolveNotificationChannels(),
        );
    }

    public function notifyRestoreFailed(Restore $restore, \Throwable $exception): void
    {
        $server = $restore->targetServer;

        if (! $server->shouldNotifyOn('failure')) {
            return;
        }

        $this->sendToChannels(
            new RestoreFailedNotification($restore, $exception),
            $server->resolveNotificationChannels(),
        );
    }

    public function notifyRestoreSuccess(Restore $restore): void
    {
        $server = $restore->targetServer;

        if (! $server->shouldNotifyOn('success')) {
            return;
        }

        $this->sendToChannels(
            new RestoreSuccessNotification($restore),
            $server->resolveNotificationChannels(),
        );
    }

    /**
     * @param  Collection<int, array{server: string, database: string, filename: string, database_server_id: string}>  $missingSnapshots
     * @param  Collection<int, string>  $affectedServerIds
     */
    public function notifySnapshotsMissing(Collection $missingSnapshots, Collection $affectedServerIds): void
    {
        $channels = DatabaseServer::whereIn('id', $affectedServerIds)
            ->get()
            ->filter(fn (DatabaseServer $server) => $server->shouldNotifyOn('failure'))
            ->flatMap(fn (DatabaseServer $server) => $server->resolveNotificationChannels())
            ->unique('id');

        $this->sendToChannels(
            new SnapshotsMissingNotification($missingSnapshots), // @phpstan-ignore argument.type
            $channels,
        );
    }

    /**
     * Send a test notification to a specific channel.
     */
    public function sendTestNotification(NotificationChannel $channel): void
    {
        $this->sendToChannel(new TestNotification($channel->name), $channel);
    }

    /**
     * @param  Collection<int, NotificationChannel>|iterable<NotificationChannel>  $channels
     */
    private function sendToChannels(Notification $notification, iterable $channels): void
    {
        foreach ($channels as $channel) {
            $this->sendToChannel($notification, $channel);
        }
    }

    private function sendToChannel(Notification $notification, NotificationChannel $channel): void
    {
        $config = $channel->getDecryptedConfig();
        $routeKey = $channel->type->routeKey();
        $routeValue = $channel->type->routeValue($config);

        if (! $routeValue) {
            return;
        }

        $this->refreshVendorServiceConfig($channel->type, $config);

        $notifiable = new ChannelNotifiable(
            routes: [$routeKey => $routeValue],
            channelConfig: $config,
        );

        \Illuminate\Support\Facades\Notification::send($notifiable, $notification);
    }

    /**
     * Refresh third-party service configs before sending.
     * Critical for Octane where boot-time config may be stale.
     *
     * @param  array<string, mixed>  $config
     */
    private function refreshVendorServiceConfig(NotificationChannelType $type, array $config): void
    {
        match ($type) {
            NotificationChannelType::Discord => config(['services.discord.token' => $config['token'] ?? null]),
            NotificationChannelType::Telegram => config(['services.telegram-bot-api.token' => $config['bot_token'] ?? null]),
            NotificationChannelType::Pushover => config(['services.pushover.token' => $config['token'] ?? null]),
            default => null,
        };
    }
}
