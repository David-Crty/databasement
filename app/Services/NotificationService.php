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
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use NotificationChannels\Discord\Discord;

class NotificationService
{
    public function notifyBackupFailed(Snapshot $snapshot, \Throwable $exception): void
    {
        $this->safely(fn () => $this->notifyServer(
            $snapshot->databaseServer,
            'failure',
            new BackupFailedNotification($snapshot, $exception),
        ));
    }

    public function notifyBackupSuccess(Snapshot $snapshot): void
    {
        $this->safely(fn () => $this->notifyServer(
            $snapshot->databaseServer,
            'success',
            new BackupSuccessNotification($snapshot),
        ));
    }

    public function notifyRestoreFailed(Restore $restore, \Throwable $exception): void
    {
        $this->safely(fn () => $this->notifyServer(
            $restore->targetServer,
            'failure',
            new RestoreFailedNotification($restore, $exception),
        ));
    }

    public function notifyRestoreSuccess(Restore $restore): void
    {
        $this->safely(fn () => $this->notifyServer(
            $restore->targetServer,
            'success',
            new RestoreSuccessNotification($restore),
        ));
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
        $this->safely(function () use ($missingSnapshots, $affectedServerIds) {
            $channels = DatabaseServer::whereIn('id', $affectedServerIds)
                ->get()
                ->filter(fn (DatabaseServer $server) => $server->shouldNotifyOn('failure'))
                ->flatMap(fn (DatabaseServer $server) => $server->resolveNotificationChannels())
                ->unique('id');

            $this->sendToChannels(
                new SnapshotsMissingNotification($missingSnapshots), // @phpstan-ignore argument.type
                $channels,
            );
        });
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
     * Notifications must never block or fail the operation that triggered
     * them: any error building or dispatching one (channel resolution, DB
     * reads, notification construction) is logged and swallowed. Per-channel
     * send errors are additionally isolated in sendToChannels so one broken
     * channel cannot block the remaining ones.
     */
    private function safely(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('Notification dispatch failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  Collection<int, NotificationChannel>|iterable<NotificationChannel>  $channels
     */
    private function sendToChannels(Notification $notification, iterable $channels): void
    {
        foreach ($channels as $channel) {
            try {
                $this->sendToChannel($notification, $channel);
            } catch (\Throwable $e) {
                Log::warning('Notification send failed', [
                    'channel_id' => $channel->id,
                    'channel_type' => $channel->type->value,
                    'notification' => get_class($notification),
                    'error' => $e->getMessage(),
                ]);
            }
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
     *
     * @param  array<string, mixed>  $config
     */
    private function refreshVendorServiceConfig(NotificationChannelType $type, array $config): void
    {
        match ($type) {
            NotificationChannelType::Discord => $this->refreshDiscordToken($config['token'] ?? null),
            NotificationChannelType::Telegram => config(['services.telegram-bot-api.token' => $config['bot_token'] ?? null]),
            NotificationChannelType::Pushover => config(['services.pushover.token' => $config['token'] ?? null]),
            default => null,
        };

        // The ChannelManager caches resolved channel drivers per process, so in
        // the long-running queue worker a cached driver keeps the API client
        // (and token) it was first built with. Drop the cache so the next send
        // resolves a fresh client from the config/bindings set above. The
        // instanceof guard skips this when the facade is faked (tests), where
        // no real drivers are ever resolved.
        if (in_array($type, [NotificationChannelType::Discord, NotificationChannelType::Telegram, NotificationChannelType::Pushover], true)) {
            $manager = NotificationFacade::getFacadeRoot();

            if ($manager instanceof ChannelManager) {
                $manager->forgetDrivers();
            }
        }
    }

    /**
     * Unlike the Telegram/Pushover providers (which read config lazily at
     * resolve time), the Discord provider captures the token once at boot
     * via a contextual binding — with no token in services config at boot,
     * resolving the channel throws an unresolvable-dependency error. So the
     * binding itself must be re-registered with the channel's token.
     */
    private function refreshDiscordToken(?string $token): void
    {
        config(['services.discord.token' => $token]);

        app()->when(Discord::class)
            ->needs('$token')
            ->give($token);
    }
}
