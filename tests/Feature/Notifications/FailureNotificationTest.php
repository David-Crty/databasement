<?php

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Notifications\BackupFailedNotification;
use App\Notifications\RestoreFailedNotification;
use App\Notifications\SnapshotsMissingNotification;
use App\Services\Backup\BackupJobFactory;
use App\Services\FailureNotificationService;
use Illuminate\Support\Facades\Notification;

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
        'notifications.mail.to' => null,
        'notifications.slack.webhook_url' => null,
        'notifications.discord.channel_id' => null,
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

test('notification is sent to slack when configured', function () {
    config([
        'notifications.enabled' => true,
        'notifications.mail.to' => null,
        'notifications.slack.webhook_url' => 'https://hooks.slack.com/services/test',
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = createTestSnapshot($server);

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, new \Exception('Error'));

    Notification::assertSentOnDemand(
        BackupFailedNotification::class,
        fn ($notification, $channels, $notifiable) => in_array('slack', $channels)
            && $notifiable->routes['slack'] === 'https://hooks.slack.com/services/test'
    );
});

test('notification is sent to discord only when configured', function () {
    config([
        'notifications.enabled' => true,
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
    'backup' => ['backup', '🚨 Backup Failed', 'Server'],
    'restore' => ['restore', '🚨 Restore Failed', 'Target Server'],
]);

test('ProcessBackupJob sends notification when backup fails', function () {
    config([
        'notifications.enabled' => true,
        'notifications.mail.to' => 'admin@example.com',
    ]);

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
        ->and($slack)->toBeInstanceOf(\Illuminate\Notifications\Slack\SlackMessage::class)
        ->and($discord)->toBeInstanceOf(\NotificationChannels\Discord\DiscordMessage::class);
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
    config([
        'notifications.enabled' => true,
        'notifications.mail.to' => 'admin@example.com',
    ]);

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
