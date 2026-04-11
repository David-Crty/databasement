<?php

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupSuccessNotification;
use App\Notifications\ChannelNotifiable;
use App\Notifications\RestoreSuccessNotification;
use App\Notifications\SuccessNotificationMessage;
use App\Services\Backup\BackupJobFactory;
use App\Services\NotificationService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Discord\DiscordMessage;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramMessage;

function createSuccessSnapshot(DatabaseServer $server): Snapshot
{
    return app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];
}

function createSuccessRestore(Snapshot $snapshot, DatabaseServer $server): Restore
{
    $job = BackupJob::create(['type' => 'restore', 'status' => 'pending', 'started_at' => now()]);

    return Restore::create([
        'backup_job_id' => $job->id,
        'snapshot_id' => $snapshot->id,
        'target_server_id' => $server->id,
        'schema_name' => 'restored_db',
    ]);
}

function sentSuccessNotifications(string $notificationClass): \Illuminate\Support\Collection
{
    $fake = Notification::getFacadeRoot();
    $all = (new ReflectionProperty($fake, 'notifications'))->getValue($fake);
    $results = collect();

    foreach ($all[ChannelNotifiable::class] ?? [] as $keyGroup) {
        foreach ($keyGroup[$notificationClass] ?? [] as $entry) {
            $results->push($entry);
        }
    }

    return $results;
}

// --- NotificationService success dispatch ---

test('backup success notification is sent when trigger is all', function () {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'name' => 'Production DB',
        'database_names' => ['myapp'],
        'notification_trigger' => 'all',
    ]);
    $snapshot = createSuccessSnapshot($server);

    app(NotificationService::class)->notifyBackupSuccess($snapshot);

    $sent = sentSuccessNotifications(BackupSuccessNotification::class);
    expect($sent)->toHaveCount(1);
    expect($sent->first()['notification']->snapshot->id)->toBe($snapshot->id);
});

test('restore success notification is sent when trigger is all', function () {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'name' => 'Staging DB',
        'database_names' => ['staging'],
        'notification_trigger' => 'all',
    ]);
    $snapshot = createSuccessSnapshot($server);
    $restore = createSuccessRestore($snapshot, $server);

    app(NotificationService::class)->notifyRestoreSuccess($restore);

    $sent = sentSuccessNotifications(RestoreSuccessNotification::class);
    expect($sent)->toHaveCount(1);
    expect($sent->first()['notification']->restore->id)->toBe($restore->id);
});

test('success notification is sent when trigger is success', function () {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['testdb'],
        'notification_trigger' => 'success',
    ]);
    $snapshot = createSuccessSnapshot($server);

    app(NotificationService::class)->notifyBackupSuccess($snapshot);

    $sent = sentSuccessNotifications(BackupSuccessNotification::class);
    expect($sent)->toHaveCount(1);
});

test('success notification is not sent when trigger is failure', function () {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['testdb'],
        'notification_trigger' => 'failure',
    ]);
    $snapshot = createSuccessSnapshot($server);

    app(NotificationService::class)->notifyBackupSuccess($snapshot);

    Notification::assertNothingSent();
});

test('success notification is not sent when trigger is none', function () {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['testdb'],
        'notification_trigger' => 'none',
    ]);
    $snapshot = createSuccessSnapshot($server);

    app(NotificationService::class)->notifyBackupSuccess($snapshot);

    Notification::assertNothingSent();
});

test('failure notification is not sent when trigger is success only', function () {
    NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['testdb'],
        'notification_trigger' => 'success',
    ]);
    $snapshot = createSuccessSnapshot($server);

    app(NotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    Notification::assertNothingSent();
});

// --- SuccessNotificationMessage rendering ---

test('backup success notification renders mail', function () {
    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['testdb']]);
    $snapshot = createSuccessSnapshot($server);

    $notification = new BackupSuccessNotification($snapshot);
    $message = $notification->getMessage();

    expect($message)->toBeInstanceOf(SuccessNotificationMessage::class);

    $mail = $message->toMail();
    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toContain('Backup Succeeded');
});

test('restore success notification renders mail', function () {
    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_names' => ['testdb']]);
    $snapshot = createSuccessSnapshot($server);
    $restore = createSuccessRestore($snapshot, $server);

    $notification = new RestoreSuccessNotification($restore);
    $message = $notification->getMessage();

    $mail = $message->toMail();
    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toContain('Restore Succeeded');
});

test('success message renders to all channel formats', function () {
    $message = new SuccessNotificationMessage(
        title: 'Test Title',
        body: 'Test body',
        actionText: 'View Details',
        actionUrl: 'https://example.com',
        footerText: 'Footer',
        fields: ['Server' => 'Test Server', 'Database' => 'testdb'],
    );

    // Mail
    $mail = $message->toMail();
    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('Test Title');

    // Slack
    $slack = $message->toSlack();
    expect($slack)->toBeInstanceOf(SlackMessage::class);

    // Discord
    $discord = $message->toDiscord();
    expect($discord)->toBeInstanceOf(DiscordMessage::class);

    // Telegram
    $telegram = $message->toTelegram('12345');
    expect($telegram)->toBeInstanceOf(TelegramMessage::class);

    // Pushover
    $pushover = $message->toPushover();
    expect($pushover)->toBeInstanceOf(PushoverMessage::class);

    // Gotify
    $gotify = $message->toGotify();
    expect($gotify)->toBeArray()
        ->and($gotify['title'])->toBe('Test Title')
        ->and($gotify['priority'])->toBe(4);

    // Discord Webhook
    $discordWebhook = $message->toDiscordWebhook();
    expect($discordWebhook)->toBeArray()
        ->and($discordWebhook['embeds'][0]['title'])->toBe('Test Title')
        ->and($discordWebhook['embeds'][0]['color'])->toBe(3066993);

    // Webhook
    $webhook = $message->toWebhook('BackupSuccessNotification');
    expect($webhook)->toBeArray()
        ->and($webhook['event'])->toBe('BackupSuccessNotification')
        ->and($webhook['title'])->toBe('Test Title')
        ->and($webhook['action_url'])->toBe('https://example.com')
        ->and($webhook)->not->toHaveKey('error');
});
