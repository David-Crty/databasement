<?php

use App\Facades\AppConfig;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use App\Notifications\Channels\GotifyChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\RestoreFailedNotification;
use App\Notifications\SnapshotsMissingNotification;
use App\Services\Backup\BackupJobFactory;
use App\Services\FailureNotificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Discord\DiscordMessage;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

function createTestSnapshot(DatabaseServer $server): Snapshot
{
    $factory = app(BackupJobFactory::class);

    return $factory->createSnapshots($server, 'manual')[0];
}

function createTestRestore(Snapshot $snapshot, DatabaseServer $server): Restore
{
    $restoreJob = BackupJob::create([
        'type' => 'restore',
        'status' => 'pending',
        'started_at' => now(),
    ]);

    return Restore::create([
        'backup_job_id' => $restoreJob->id,
        'snapshot_id' => $snapshot->id,
        'target_server_id' => $server->id,
        'schema_name' => 'restored_db',
    ]);
}

test('notification is sent with correct details', function (string $type) {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $server = DatabaseServer::factory()->create([
        'name' => 'Production DB',
        'database_names' => ['myapp'],
    ]);
    $snapshot = createTestSnapshot($server);
    $exception = new \Exception('Connection refused');

    if ($type === 'backup') {
        app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);

        Notification::assertSentOnDemand(
            BackupFailedNotification::class,
            fn (BackupFailedNotification $n) => $n->snapshot->id === $snapshot->id
                && $n->exception->getMessage() === $exception->getMessage()
        );
    } else {
        $restore = createTestRestore($snapshot, $server);
        app(FailureNotificationService::class)->notifyRestoreFailed($restore, $exception);

        Notification::assertSentOnDemand(
            RestoreFailedNotification::class,
            fn (RestoreFailedNotification $n) => $n->restore->id === $restore->id
                && $n->exception->getMessage() === $exception->getMessage()
        );
    }
})->with(['backup', 'restore']);

test('notification is not sent when disabled', function () {
    AppConfig::set('notifications.enabled', false);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    Notification::assertNothingSent();
});

test('notification is not sent when no routes configured', function (string $type) {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', null);
    AppConfig::set('notifications.slack.webhook_url', null);
    AppConfig::set('notifications.discord.channel_id', null);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    if ($type === 'backup') {
        app(FailureNotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));
    } else {
        $restore = createTestRestore($snapshot, $server);
        app(FailureNotificationService::class)->notifyRestoreFailed($restore, new \Exception('Error'));
    }

    Notification::assertNothingSent();
})->with(['backup', 'restore']);

test('notification is sent to channel when configured', function (string $configKey, string $configValue, string $expectedChannel, string $routeKey) {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', null);
    AppConfig::set($configKey, $configValue);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    Notification::assertSentOnDemand(
        BackupFailedNotification::class,
        fn ($notification, $channels, $notifiable) => in_array($expectedChannel, $channels)
            && $notifiable->routes[$routeKey] === $configValue
    );
})->with([
    'slack' => ['notifications.slack.webhook_url', 'https://hooks.slack.com/services/test', 'slack', 'slack'],
    'discord' => ['notifications.discord.channel_id', '123456789012345678', 'discord', 'discord'],
    'telegram' => ['notifications.telegram.chat_id', '123456', TelegramChannel::class, 'telegram'],
    'pushover' => ['notifications.pushover.user_key', 'user-key-123', PushoverChannel::class, 'pushover'],
    'gotify' => ['notifications.gotify.url', 'https://gotify.example.com', GotifyChannel::class, 'gotify'],
    'webhook' => ['notifications.webhook.url', 'https://webhook.example.com/hook', WebhookChannel::class, 'webhook'],
]);

test('via method returns channels based on configured routes', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    $notification = new BackupFailedNotification($snapshot, new \Exception('Error'));

    // All channels
    $channels = $notification->via((object) ['routes' => [
        'mail' => 'admin@example.com',
        'slack' => 'https://hooks.slack.com/test',
        'discord' => '123456789012345678',
        'telegram' => '123456',
        'pushover' => 'user-key-123',
        'gotify' => 'https://gotify.example.com',
        'webhook' => 'https://webhook.example.com/hook',
    ]]);
    expect($channels)->toBe([
        'mail',
        'slack',
        'discord',
        TelegramChannel::class,
        PushoverChannel::class,
        GotifyChannel::class,
        WebhookChannel::class,
    ]);

    // Single channel
    $channels = $notification->via((object) ['routes' => ['mail' => 'admin@example.com']]);
    expect($channels)->toBe(['mail']);

    // No routes
    $channels = $notification->via((object) ['routes' => []]);
    expect($channels)->toBe([]);
});

test('backup and restore notifications render mail with correct details', function (string $type, string $expectedSubjectPrefix, string $serverFieldKey) {
    $server = DatabaseServer::factory()->create([
        'name' => 'Test Server',
        'database_names' => ['testdb'],
    ]);
    $snapshot = createTestSnapshot($server);
    $exception = new \Exception('Test error');

    if ($type === 'backup') {
        $notification = new BackupFailedNotification($snapshot, $exception);
    } else {
        $restore = createTestRestore($snapshot, $server);
        $notification = new RestoreFailedNotification($restore, $exception);
    }

    $mail = $notification->toMail((object) []);

    expect($mail->subject)->toBe("{$expectedSubjectPrefix}: Test Server")
        ->and($mail->viewData['fields'][$serverFieldKey])->toBe('Test Server');
})->with([
    'backup' => ['backup', "\u{1F6A8} Backup Failed", 'Server'],
    'restore' => ['restore', "\u{1F6A8} Restore Failed", 'Target Server'],
]);

test('notification renders channel correctly', function (Closure $assert) {
    $server = DatabaseServer::factory()->create([
        'name' => 'Test Server',
        'database_names' => ['testdb'],
    ]);
    $snapshot = createTestSnapshot($server);
    $notification = new BackupFailedNotification($snapshot, new \Exception('Test error'));

    $assert($notification);
})->with([
    'mail' => [function (BackupFailedNotification $notification) {
        $mail = $notification->toMail((object) []);
        expect($mail->subject)->toContain('Backup Failed')
            ->and($mail->markdown)->toBe('mail.failed-notification')
            ->and($mail->viewData['errorMessage'])->toBe('Test error');
    }],
    'slack' => [function (BackupFailedNotification $notification) {
        expect($notification->toSlack((object) []))->toBeInstanceOf(SlackMessage::class);
    }],
    'discord' => [function (BackupFailedNotification $notification) {
        expect($notification->toDiscord((object) []))->toBeInstanceOf(DiscordMessage::class);
    }],
    'telegram' => [function (BackupFailedNotification $notification) {
        $telegram = $notification->toTelegram((object) ['routes' => ['telegram' => '123456']]);
        expect($telegram)->toBeInstanceOf(TelegramMessage::class)
            ->and($telegram->getPayloadValue('chat_id'))->toBe('123456')
            ->and($telegram->getPayloadValue('text'))->toContain('Backup Failed')
            ->and($telegram->getPayloadValue('text'))->toContain('Test error')
            ->and($telegram->getPayloadValue('parse_mode'))->toBe('HTML');
    }],
    'pushover' => [function (BackupFailedNotification $notification) {
        $pushover = $notification->toPushover((object) []);
        expect($pushover)->toBeInstanceOf(PushoverMessage::class)
            ->and($pushover->toArray()['title'])->toContain('Backup Failed')
            ->and($pushover->toArray()['message'])->toContain('Test error');
    }],
    'gotify' => [function (BackupFailedNotification $notification) {
        $gotify = $notification->toGotify((object) []);
        expect($gotify)->toBeArray()
            ->and($gotify['title'])->toContain('Backup Failed')
            ->and($gotify['message'])->toContain('Test error')
            ->and($gotify['priority'])->toBe(8);
    }],
    'webhook' => [function (BackupFailedNotification $notification) {
        $webhook = $notification->toWebhook((object) []);
        expect($webhook)->toBeArray()
            ->and($webhook['event'])->toBe('notification.failed')
            ->and($webhook['title'])->toContain('Backup Failed')
            ->and($webhook['error'])->toBe('Test error')
            ->and($webhook['action_url'])->toBeString()
            ->and($webhook['timestamp'])->toBeString();
    }],
]);

test('custom channel sends HTTP request', function (string $channelClass, array $config, Closure $assertRequest) {
    Http::fake();

    foreach ($config as $key => $value) {
        AppConfig::set($key, $value);
    }

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);
    $notification = new BackupFailedNotification($snapshot, new \Exception('Test error'));

    (new $channelClass)->send((object) [], $notification);

    Http::assertSent($assertRequest);
})->with([
    'gotify' => [
        GotifyChannel::class,
        ['notifications.gotify.url' => 'https://gotify.example.com', 'notifications.gotify.token' => 'app-token'],
        fn (Request $request) => $request->url() === 'https://gotify.example.com/message'
            && $request->hasHeader('X-Gotify-Key', 'app-token')
            && str_contains($request['title'], 'Backup Failed'),
    ],
    'webhook' => [
        WebhookChannel::class,
        ['notifications.webhook.url' => 'https://webhook.example.com/hook', 'notifications.webhook.secret' => 'my-secret'],
        fn (Request $request) => $request->url() === 'https://webhook.example.com/hook'
            && $request->hasHeader('X-Webhook-Token', 'my-secret')
            && $request->hasHeader('X-Webhook-Event', 'BackupFailedNotification')
            && str_contains($request['title'], 'Backup Failed'),
    ],
]);

test('custom channel logs on HTTP failure without throwing', function (string $channelClass, array $config, string $expectedLogMessage) {
    Http::fake(fn () => Http::response('Server Error', 500));

    foreach ($config as $key => $value) {
        AppConfig::set($key, $value);
    }

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);
    $notification = new BackupFailedNotification($snapshot, new \Exception('Test error'));

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === $expectedLogMessage && $context['status'] === 500);

    (new $channelClass)->send((object) [], $notification);
})->with([
    'gotify' => [
        GotifyChannel::class,
        ['notifications.gotify.url' => 'https://gotify.example.com', 'notifications.gotify.token' => 'app-token'],
        'Gotify notification failed',
    ],
    'webhook' => [
        WebhookChannel::class,
        ['notifications.webhook.url' => 'https://webhook.example.com/hook'],
        'Webhook notification failed',
    ],
]);

test('ProcessBackupJob sends notification when backup fails', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $server = DatabaseServer::factory()->create([
        'name' => 'Production MySQL',
        'database_names' => ['myapp'],
    ]);
    $snapshot = createTestSnapshot($server);

    $job = new \App\Jobs\ProcessBackupJob($snapshot->id);
    $exception = new \Exception('Access denied for user');

    // Call the failed method directly (simulating job failure)
    $job->failed($exception);

    // Verify notification was sent
    Notification::assertSentOnDemand(
        BackupFailedNotification::class,
        fn (BackupFailedNotification $n) => $n->snapshot->id === $snapshot->id
            && $n->exception->getMessage() === 'Access denied for user'
    );
});

test('SnapshotsMissingNotification renders mail, slack and discord correctly', function () {
    $missingSnapshots = collect([
        ['server' => 'Prod DB', 'database' => 'myapp', 'filename' => 'backup-1.sql.gz'],
        ['server' => 'Prod DB', 'database' => 'users', 'filename' => 'backup-2.sql.gz'],
    ]);

    $notification = new SnapshotsMissingNotification($missingSnapshots);

    $mail = $notification->toMail((object) []);
    $slack = $notification->toSlack((object) []);
    $discord = $notification->toDiscord((object) []);

    expect($mail->subject)->toContain('2 backup files missing')
        ->and($mail->viewData['errorMessage'])->toContain('Prod DB / myapp')
        ->and($mail->viewData['errorMessage'])->toContain('backup-1.sql.gz')
        ->and($slack)->toBeInstanceOf(SlackMessage::class)
        ->and($discord)->toBeInstanceOf(DiscordMessage::class);
});

test('SnapshotsMissingNotification truncates file list beyond 10 items', function () {
    $missingSnapshots = collect(range(1, 12))->map(fn ($i) => [
        'server' => "Server {$i}",
        'database' => "db_{$i}",
        'filename' => "backup-{$i}.sql.gz",
    ]);

    $notification = new SnapshotsMissingNotification($missingSnapshots);

    $mail = $notification->toMail((object) []);

    expect($mail->viewData['errorMessage'])->toContain('backup-10.sql.gz')
        ->and($mail->viewData['errorMessage'])->not->toContain('backup-11.sql.gz')
        ->and($mail->viewData['errorMessage'])->toContain('... and 2 more');
});

test('ProcessRestoreJob sends notification when restore fails', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $server = DatabaseServer::factory()->create([
        'name' => 'Production MySQL',
        'database_names' => ['myapp'],
    ]);
    $snapshot = createTestSnapshot($server);
    $restore = createTestRestore($snapshot, $server);

    $job = new \App\Jobs\ProcessRestoreJob($restore->id);
    $exception = new \Exception('Connection refused');

    // Call the failed method directly (simulating job failure)
    $job->failed($exception);

    // Verify notification was sent
    Notification::assertSentOnDemand(
        RestoreFailedNotification::class,
        fn (RestoreFailedNotification $n) => $n->restore->id === $restore->id
            && $n->exception->getMessage() === 'Connection refused'
    );
});
