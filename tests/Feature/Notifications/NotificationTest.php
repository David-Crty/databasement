<?php

use App\Enums\NotificationType;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use App\Notifications\BackupSuccessNotification;
use App\Notifications\ChannelNotifiable;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Channels\GotifyChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\NotificationMessage;
use App\Notifications\RestoreFailedNotification;
use App\Notifications\RestoreSuccessNotification;
use App\Notifications\SnapshotsMissingNotification;
use App\Services\Backup\BackupJobFactory;
use App\Services\NotificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Discord\DiscordChannel;
use NotificationChannels\Discord\DiscordMessage;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * Create a completed snapshot for the given server via BackupJobFactory.
 */
function notificationSnapshot(DatabaseServer $server): Snapshot
{
    return app(BackupJobFactory::class)
        ->createSnapshots($server->backups->first(), 'manual')[0];
}

/**
 * Create a Restore targeting the given server for the given snapshot.
 */
function notificationRestore(Snapshot $snapshot, DatabaseServer $server): Restore
{
    $job = BackupJob::create([
        'type' => 'restore',
        'status' => 'pending',
        'started_at' => now(),
    ]);

    return Restore::create([
        'backup_job_id' => $job->id,
        'snapshot_id' => $snapshot->id,
        'target_server_id' => $server->id,
        'schema_name' => 'restored_db',
    ]);
}

/**
 * Collect all sent notifications of the given class delivered to ChannelNotifiable instances.
 *
 * @return \Illuminate\Support\Collection<int, array{notification: mixed, channels: array<int, string>, notifiable: ChannelNotifiable}>
 */
function sentChannelNotifications(string $notificationClass): \Illuminate\Support\Collection
{
    /** @var \Illuminate\Support\Testing\Fakes\NotificationFake $fake */
    $fake = Notification::getFacadeRoot();
    $results = collect();

    foreach ($fake->sentNotifications()[ChannelNotifiable::class] ?? [] as $keyGroup) {
        foreach ($keyGroup[$notificationClass] ?? [] as $entry) {
            $results->push($entry);
        }
    }

    return $results;
}

// --- Dispatch & trigger handling ---

test('failure notification is sent with correct details', function (string $type) {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'name' => 'Production DB',
        'database_names' => ['myapp'],
    ]);
    $snapshot = notificationSnapshot($server);
    $exception = new \Exception('Connection refused');

    if ($type === 'backup') {
        app(NotificationService::class)->notifyBackupFailed($snapshot, $exception);

        $sent = sentChannelNotifications(BackupFailedNotification::class);
        expect($sent)->toHaveCount(1);
        $notification = $sent->first()['notification'];
        expect($notification->snapshot->id)->toBe($snapshot->id)
            ->and($notification->exception->getMessage())->toBe($exception->getMessage());
    } else {
        $restore = notificationRestore($snapshot, $server);
        app(NotificationService::class)->notifyRestoreFailed($restore, $exception);

        $sent = sentChannelNotifications(RestoreFailedNotification::class);
        expect($sent)->toHaveCount(1);
        $notification = $sent->first()['notification'];
        expect($notification->restore->id)->toBe($restore->id)
            ->and($notification->exception->getMessage())->toBe($exception->getMessage());
    }
})->with(['backup', 'restore']);

test('success notification is sent with correct details', function (string $type) {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'name' => 'Production DB',
        'database_names' => ['myapp'],
        'notification_trigger' => 'all',
    ]);
    $snapshot = notificationSnapshot($server);

    if ($type === 'backup') {
        app(NotificationService::class)->notifyBackupSuccess($snapshot);

        $sent = sentChannelNotifications(BackupSuccessNotification::class);
        expect($sent)->toHaveCount(1);
        expect($sent->first()['notification']->snapshot->id)->toBe($snapshot->id);
    } else {
        $restore = notificationRestore($snapshot, $server);
        app(NotificationService::class)->notifyRestoreSuccess($restore);

        $sent = sentChannelNotifications(RestoreSuccessNotification::class);
        expect($sent)->toHaveCount(1);
        expect($sent->first()['notification']->restore->id)->toBe($restore->id);
    }
})->with(['backup', 'restore']);

test('notification trigger controls which notifications are sent', function (string $trigger, string $event, bool $shouldSend) {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['testdb'],
        'notification_trigger' => $trigger,
    ]);
    $snapshot = notificationSnapshot($server);

    if ($event === 'success') {
        app(NotificationService::class)->notifyBackupSuccess($snapshot);
    } else {
        app(NotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));
    }

    if ($shouldSend) {
        expect(sentChannelNotifications(
            $event === 'success' ? BackupSuccessNotification::class : BackupFailedNotification::class
        ))->toHaveCount(1);
    } else {
        Notification::assertNothingSent();
    }
})->with([
    'all + success' => ['all', 'success', true],
    'all + failure' => ['all', 'failure', true],
    'success + success' => ['success', 'success', true],
    'success + failure' => ['success', 'failure', false],
    'failure + failure' => ['failure', 'failure', true],
    'failure + success' => ['failure', 'success', false],
    'none + success' => ['none', 'success', false],
    'none + failure' => ['none', 'failure', false],
]);

test('notification is not sent when no channels exist', function (string $type) {
    // No NotificationChannel records exist
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);

    if ($type === 'backup') {
        app(NotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));
    } else {
        $restore = notificationRestore($snapshot, $server);
        app(NotificationService::class)->notifyRestoreFailed($restore, new \Exception('Error'));
    }

    Notification::assertNothingSent();
})->with(['backup', 'restore']);

test('notification is sent to channel when configured', function (string $factoryState, array $configOverrides, string $expectedChannel, string $routeKey) {
    $channel = NotificationChannel::factory()->{$factoryState}()->create(
        ! empty($configOverrides) ? ['config' => $configOverrides] : []
    );

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);

    app(NotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    $sent = sentChannelNotifications(BackupFailedNotification::class);
    expect($sent)->toHaveCount(1);
    expect($sent->first()['channels'])->toContain($expectedChannel);
})->with([
    'slack' => ['slack', ['webhook_url' => 'https://hooks.slack.com/services/test'], 'slack', 'slack'],
    'discord' => ['discord', ['token' => 'bot-token', 'channel_id' => '123456789012345678'], DiscordChannel::class, 'discord'],
    'telegram' => ['telegram', ['bot_token' => 'bot-token', 'chat_id' => '123456'], TelegramChannel::class, 'telegram'],
    'pushover' => ['pushover', ['token' => 'push-token', 'user_key' => 'user-key-123'], PushoverChannel::class, 'pushover'],
    'gotify' => ['gotify', ['url' => 'https://gotify.example.com', 'token' => 'app-token'], GotifyChannel::class, 'gotify'],
    'discordWebhook' => ['discordWebhook', ['url' => 'https://discord.com/api/webhooks/123/abc'], DiscordWebhookChannel::class, 'discord_webhook'],
    'webhook' => ['webhook', ['url' => 'https://webhook.example.com/hook', 'secret' => 'my-secret'], WebhookChannel::class, 'webhook'],
]);

test('send refreshes service configs from channel config before dispatching', function () {
    NotificationChannel::factory()->pushover()->create([
        'config' => ['token' => 'app-token-fresh', 'user_key' => 'user-key-123'],
    ]);
    NotificationChannel::factory()->discord()->create([
        'config' => ['token' => 'discord-token-fresh', 'channel_id' => '123456789'],
    ]);
    NotificationChannel::factory()->telegram()->create([
        'config' => ['bot_token' => 'telegram-token-fresh', 'chat_id' => '999'],
    ]);

    // Simulate stale config: services.* keys are empty
    config([
        'services.pushover.token' => null,
        'services.discord.token' => null,
        'services.telegram-bot-api.token' => null,
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);

    app(NotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    // After sending, the service should have refreshed config from channel records
    $sent = sentChannelNotifications(BackupFailedNotification::class);
    expect($sent)->toHaveCount(3);
});

test('via method returns channels based on configured routes', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);

    $notification = new BackupFailedNotification($snapshot, new \Exception('Error'));

    // All channels
    $channels = $notification->via((object) ['routes' => [
        'mail' => 'admin@example.com',
        'slack' => 'https://hooks.slack.com/test',
        'discord' => '123456789012345678',
        'telegram' => '123456',
        'pushover' => 'user-key-123',
        'gotify' => 'https://gotify.example.com',
        'discord_webhook' => 'https://discord.com/api/webhooks/123/abc',
        'webhook' => 'https://webhook.example.com/hook',
    ]]);
    expect($channels)->toBe([
        'mail',
        'slack',
        DiscordChannel::class,
        TelegramChannel::class,
        PushoverChannel::class,
        GotifyChannel::class,
        DiscordWebhookChannel::class,
        WebhookChannel::class,
    ]);

    // Single channel
    $channels = $notification->via((object) ['routes' => ['mail' => 'admin@example.com']]);
    expect($channels)->toBe(['mail']);

    // No routes
    $channels = $notification->via((object) ['routes' => []]);
    expect($channels)->toBe([]);
});

// --- Message rendering ---

test('failure notifications render mail with correct details', function (string $type, string $expectedSubjectPrefix, string $serverFieldKey) {
    $server = DatabaseServer::factory()->create([
        'name' => 'Test Server',
        'database_names' => ['testdb'],
    ]);
    $snapshot = notificationSnapshot($server);
    $exception = new \Exception('Test error');

    if ($type === 'backup') {
        $notification = new BackupFailedNotification($snapshot, $exception);
    } else {
        $restore = notificationRestore($snapshot, $server);
        $notification = new RestoreFailedNotification($restore, $exception);
    }

    $mail = $notification->toMail((object) []);

    expect($mail->subject)->toBe("{$expectedSubjectPrefix}: Test Server")
        ->and($mail->viewData['fields'][$serverFieldKey])->toBe('Test Server');
})->with([
    'backup' => ['backup', "\u{1F6A8} Backup Failed", 'Server'],
    'restore' => ['restore', "\u{1F6A8} Restore Failed", 'Target Server'],
]);

test('success notifications render mail with correct details', function (string $type, string $expectedSubjectPrefix) {
    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);

    if ($type === 'backup') {
        $notification = new BackupSuccessNotification($snapshot);
    } else {
        $restore = notificationRestore($snapshot, $server);
        $notification = new RestoreSuccessNotification($restore);
    }

    $message = $notification->getMessage();
    expect($message)->toBeInstanceOf(NotificationMessage::class)
        ->and($message->type)->toBe(NotificationType::Success);

    $mail = $message->toMail();
    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toContain($expectedSubjectPrefix);
})->with([
    'backup' => ['backup', 'Backup Succeeded'],
    'restore' => ['restore', 'Restore Succeeded'],
]);

test('failure message renders every channel with error details', function (Closure $assert) {
    $server = DatabaseServer::factory()->create([
        'name' => 'Test Server',
        'database_names' => ['testdb'],
    ]);
    $snapshot = notificationSnapshot($server);
    $notification = new BackupFailedNotification($snapshot, new \Exception('Test error'));

    $assert($notification);
})->with([
    'mail' => [function (BackupFailedNotification $notification) {
        $mail = $notification->toMail((object) []);
        expect($mail->subject)->toContain('Backup Failed')
            ->and($mail->markdown)->toBe('mail.notification')
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
    'discord_webhook' => [function (BackupFailedNotification $notification) {
        $payload = $notification->toDiscordWebhook((object) []);
        expect($payload)->toBeArray()
            ->and($payload['content'])->toBeString()
            ->and($payload['embeds'])->toHaveCount(1)
            ->and($payload['embeds'][0]['title'])->toContain('Backup Failed')
            ->and($payload['embeds'][0]['color'])->toBe(15158332);
    }],
    'webhook' => [function (BackupFailedNotification $notification) {
        $webhook = $notification->toWebhook((object) []);
        expect($webhook)->toBeArray()
            ->and($webhook['event'])->toBe('BackupFailedNotification')
            ->and($webhook['title'])->toContain('Backup Failed')
            ->and($webhook['error'])->toBe('Test error')
            ->and($webhook['action_url'])->toBeString()
            ->and($webhook['timestamp'])->toBeString();
    }],
]);

test('success message renders every channel without error details', function () {
    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);
    $message = (new BackupSuccessNotification($snapshot))->getMessage();

    expect($message->toMail())->toBeInstanceOf(MailMessage::class)
        ->and($message->toSlack())->toBeInstanceOf(SlackMessage::class)
        ->and($message->toDiscord())->toBeInstanceOf(DiscordMessage::class)
        ->and($message->toTelegram('12345'))->toBeInstanceOf(TelegramMessage::class)
        ->and($message->toPushover())->toBeInstanceOf(PushoverMessage::class);

    // Gotify uses the success priority
    expect($message->toGotify()['priority'])->toBe(4);

    // Discord webhook uses the success colour
    expect($message->toDiscordWebhook()['embeds'][0]['color'])->toBe(3066993);

    // Webhook omits the error key for success
    $webhook = $message->toWebhook('BackupSuccessNotification');
    expect($webhook['event'])->toBe('BackupSuccessNotification')
        ->and($webhook)->not->toHaveKey('error');
});

test('telegram notification sets message_thread_id only when a topic id is configured', function (string $topicId, ?int $expected) {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);
    $notification = new BackupFailedNotification($snapshot, new \Exception('Test error'));

    $telegram = $notification->toTelegram((object) [
        'routes' => ['telegram' => '123456'],
        'channelConfig' => ['topic_id' => $topicId],
    ]);

    expect($telegram->getPayloadValue('chat_id'))->toBe('123456')
        ->and($telegram->getPayloadValue('message_thread_id'))->toBe($expected);
})->with([
    'with topic id' => ['42', 42],
    'without topic id' => ['', null],
]);

// --- Custom HTTP channels ---

test('custom channel sends HTTP request', function (string $channelClass, array $channelConfig, Closure $assertRequest) {
    Http::fake();

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);
    $notification = new BackupFailedNotification($snapshot, new \Exception('Test error'));

    $notifiable = new ChannelNotifiable(
        routes: [],
        channelConfig: $channelConfig,
    );

    (new $channelClass)->send($notifiable, $notification);

    Http::assertSent($assertRequest);
})->with([
    'gotify' => [
        GotifyChannel::class,
        ['url' => 'https://gotify.example.com', 'token' => 'app-token'],
        fn (Request $request) => $request->url() === 'https://gotify.example.com/message'
            && $request->hasHeader('X-Gotify-Key', 'app-token')
            && str_contains($request['title'], 'Backup Failed'),
    ],
    'discord_webhook' => [
        DiscordWebhookChannel::class,
        ['url' => 'https://discord.com/api/webhooks/123/abc'],
        fn (Request $request) => $request->url() === 'https://discord.com/api/webhooks/123/abc'
            && str_contains($request['embeds'][0]['title'], 'Backup Failed'),
    ],
    'webhook' => [
        WebhookChannel::class,
        ['url' => 'https://webhook.example.com/hook', 'secret' => 'my-secret'],
        fn (Request $request) => $request->url() === 'https://webhook.example.com/hook'
            && $request->hasHeader('X-Webhook-Token', 'my-secret')
            && $request['event'] === 'BackupFailedNotification'
            && str_contains($request['title'], 'Backup Failed'),
    ],
]);

test('custom channel throws on HTTP failure', function (string $channelClass, array $channelConfig) {
    Http::fake(fn () => Http::response('Server Error', 500));

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['testdb']]);
    $snapshot = notificationSnapshot($server);
    $notification = new BackupFailedNotification($snapshot, new \Exception('Test error'));

    $notifiable = new ChannelNotifiable(
        routes: [],
        channelConfig: $channelConfig,
    );

    expect(fn () => (new $channelClass)->send($notifiable, $notification))
        ->toThrow(\Illuminate\Http\Client\RequestException::class);
})->with([
    'gotify' => [
        GotifyChannel::class,
        ['url' => 'https://gotify.example.com', 'token' => 'app-token'],
    ],
    'discord_webhook' => [
        DiscordWebhookChannel::class,
        ['url' => 'https://discord.com/api/webhooks/123/abc'],
    ],
    'webhook' => [
        WebhookChannel::class,
        ['url' => 'https://webhook.example.com/hook'],
    ],
]);

// --- Job failure hooks ---

test('failed jobs send a failure notification', function (string $type) {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'name' => 'Production MySQL',
        'database_names' => ['myapp'],
    ]);
    $snapshot = notificationSnapshot($server);
    $exception = new \Exception('Access denied for user');

    if ($type === 'backup') {
        (new \App\Jobs\ProcessBackupJob($snapshot->id))->failed($exception);

        $sent = sentChannelNotifications(BackupFailedNotification::class);
        expect($sent)->toHaveCount(1);
        expect($sent->first()['notification']->snapshot->id)->toBe($snapshot->id)
            ->and($sent->first()['notification']->exception->getMessage())->toBe('Access denied for user');
    } else {
        $restore = notificationRestore($snapshot, $server);
        (new \App\Jobs\ProcessRestoreJob($restore->id))->failed($exception);

        $sent = sentChannelNotifications(RestoreFailedNotification::class);
        expect($sent)->toHaveCount(1);
        expect($sent->first()['notification']->restore->id)->toBe($restore->id)
            ->and($sent->first()['notification']->exception->getMessage())->toBe('Access denied for user');
    }
})->with(['backup', 'restore']);

// --- SnapshotsMissingNotification ---

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
