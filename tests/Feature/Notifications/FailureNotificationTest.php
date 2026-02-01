<?php

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use App\Notifications\RestoreFailedNotification;
use App\Services\Backup\BackupJobFactory;
use App\Services\FailureNotificationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

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
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => 'admin@example.com',
    ]);

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
    config([
        'notifications.enabled' => false,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => 'admin@example.com',
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    Notification::assertNothingSent();
});

test('notification is not sent when no routes configured', function (string $type) {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => null,
    ]);

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

test('notification is sent to slack only when configured', function () {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'slack',
        'notifications.mail.to' => null,
        'notifications.slack.webhook_url' => 'https://hooks.slack.com/services/test',
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    Notification::assertSentOnDemand(BackupFailedNotification::class);
});

test('notification is sent to discord only when configured', function () {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'discord',
        'notifications.mail.to' => null,
        'notifications.discord.channel_id' => '123456789012345678',
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    Notification::assertSentOnDemand(BackupFailedNotification::class);
});

test('via method returns channels based on configured routes', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    $notification = new BackupFailedNotification($snapshot, new \Exception('Error'));

    // All channels
    $channels = $notification->via((object) ['routes' => [
        'mail' => 'admin@example.com',
        'slack' => 'https://hooks.slack.com/test',
        'discord' => '123456789012345678',
    ]]);
    expect($channels)->toBe(['mail', 'slack', 'discord']);

    // Single channel
    $channels = $notification->via((object) ['routes' => ['mail' => 'admin@example.com']]);
    expect($channels)->toBe(['mail']);

    // No routes
    $channels = $notification->via((object) ['routes' => []]);
    expect($channels)->toBe([]);
});

test('notification renders mail, slack and discord correctly', function (string $type, string $expectedSubjectPrefix, string $serverFieldKey) {
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
    $slack = $notification->toSlack((object) []);
    $discord = $notification->toDiscord((object) []);

    expect($mail->subject)->toBe("{$expectedSubjectPrefix}: Test Server")
        ->and($mail->markdown)->toBe('mail.failed-notification')
        ->and($mail->viewData['fields'][$serverFieldKey])->toBe('Test Server')
        ->and($mail->viewData['errorMessage'])->toBe('Test error')
        ->and($slack)->toBeInstanceOf(\Illuminate\Notifications\Slack\SlackMessage::class)
        ->and($discord)->toBeInstanceOf(\NotificationChannels\Discord\DiscordMessage::class);
})->with([
    'backup' => ['backup', 'Backup Failed', 'Server'],
    'restore' => ['restore', 'Restore Failed', 'Target Server'],
]);
