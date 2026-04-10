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
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class NotificationService
{
    public function notifyBackupFailed(Snapshot $snapshot, \Throwable $exception): void
    {
        $this->notifyServer(
            $snapshot->databaseServer,
            'failure',
            new BackupFailedNotification($snapshot, $exception),
        );
    }

    public function notifyBackupSuccess(Snapshot $snapshot): void
    {
        $this->notifyServer(
            $snapshot->databaseServer,
            'success',
            new BackupSuccessNotification($snapshot),
        );
    }

    public function notifyRestoreFailed(Restore $restore, \Throwable $exception): void
    {
        $this->notifyServer(
            $restore->targetServer,
            'failure',
            new RestoreFailedNotification($restore, $exception),
        );
    }

    public function notifyRestoreSuccess(Restore $restore): void
    {
        $this->notifyServer(
            $restore->targetServer,
            'success',
            new RestoreSuccessNotification($restore),
        );
    }

    private function notifyServer(DatabaseServer $server, string $event, Notification $notification): void
    {
        if (! $server->shouldNotifyOn($event)) {
            return;
        }

        $this->sendToChannels($notification, $server->resolveNotificationChannels());
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
     * Send a fake "backup failed" notification to a specific channel for testing.
     */
    public function sendTestNotification(NotificationChannel $channel): void
    {
        $server = new DatabaseServer(['name' => '[TEST] Production Database']);
        $snapshot = new Snapshot([
            'database_name' => 'app_production',
            'backup_job_id' => 'test-notification',
        ]);
        $snapshot->setRelation('databaseServer', $server);

        $exception = new \Exception('SQLSTATE[HY000] [2002] Connection refused (This is a test notification)');

        $this->sendToChannel(new BackupFailedNotification($snapshot, $exception), $channel);
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

        NotificationFacade::send($notifiable, $notification);
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
