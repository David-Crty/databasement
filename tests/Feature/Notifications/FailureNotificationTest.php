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

function createRestore(Snapshot $snapshot, DatabaseServer $server): Restore
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

test('backup failure notification is sent when enabled', function () {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => 'admin@example.com',
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $exception = new \Exception('Connection timeout');

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);

    Notification::assertSentOnDemand(BackupFailedNotification::class);
});

test('restore failure notification is sent when enabled', function () {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => 'admin@example.com',
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    $restore = createRestore($snapshot, $server);
    $exception = new \Exception('Restore failed');

    app(FailureNotificationService::class)->notifyRestoreFailed($restore, $exception);

    Notification::assertSentOnDemand(RestoreFailedNotification::class);
});

test('notification is not sent when disabled', function () {
    config([
        'notifications.enabled' => false,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => 'admin@example.com',
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $exception = new \Exception('Connection timeout');

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);

    Notification::assertNothingSent();
});

test('notification is not sent when no routes configured', function () {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => null,
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $exception = new \Exception('Connection timeout');

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);

    Notification::assertNothingSent();
});

test('backup notification includes correct details', function () {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => 'admin@example.com',
    ]);

    $server = DatabaseServer::factory()->create([
        'name' => 'Production DB',
        'database_names' => ['myapp'],
    ]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $exception = new \Exception('Connection refused');

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);

    Notification::assertSentOnDemand(
        BackupFailedNotification::class,
        function (BackupFailedNotification $notification) use ($snapshot, $exception) {
            return $notification->snapshot->id === $snapshot->id
                && $notification->exception->getMessage() === $exception->getMessage();
        }
    );
});

test('restore notification includes correct details', function () {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'mail',
        'notifications.mail.to' => 'admin@example.com',
    ]);

    $server = DatabaseServer::factory()->create([
        'name' => 'Staging DB',
        'database_names' => ['testdb'],
    ]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    $restore = createRestore($snapshot, $server);
    $exception = new \Exception('Insufficient permissions');

    app(FailureNotificationService::class)->notifyRestoreFailed($restore, $exception);

    Notification::assertSentOnDemand(
        RestoreFailedNotification::class,
        function (RestoreFailedNotification $notification) use ($restore, $exception) {
            return $notification->restore->id === $restore->id
                && $notification->exception->getMessage() === $exception->getMessage();
        }
    );
});

test('notification sent to multiple channels', function () {
    config([
        'notifications.enabled' => true,
        'notifications.channels' => 'mail,slack',
        'notifications.mail.to' => 'admin@example.com',
        'notifications.slack.webhook_url' => 'https://hooks.slack.com/services/test',
    ]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $exception = new \Exception('Connection timeout');

    app(FailureNotificationService::class)->notifyBackupFailed($snapshot, $exception);

    Notification::assertSentOnDemand(BackupFailedNotification::class);
});
