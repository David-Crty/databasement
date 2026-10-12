<?php

use App\Actions\Backup\CompleteBackupAction;
use App\Enums\BackupJobStatus;
use App\Enums\SnapshotFileStatus;
use App\Models\NotificationChannel;
use App\Notifications\BackupSuccessNotification;
use App\Notifications\StorageLimitWarningNotification;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\VolumeTransferResult;
use Illuminate\Support\Facades\Notification;

test('it completes the job and notifies the success', function () {
    NotificationChannel::factory()->email()->create();
    $snapshot = pendingSnapshot(createDatabaseServer(['database_names' => ['app'], 'notification_trigger' => 'all']));

    CompleteBackupAction::run($snapshot, new BackupResult('app.sql.gz', 2048, 'abc123'));

    expect($snapshot->job->fresh()->status)->toBe(BackupJobStatus::Completed);
    Notification::assertSentTimes(BackupSuccessNotification::class, 1);
    Notification::assertSentTimes(StorageLimitWarningNotification::class, 0);
});

test('it warns about each volume past its notify-only storage limit', function () {
    NotificationChannel::factory()->email()->create();
    $snapshot = pendingSnapshot(createDatabaseServer(['database_names' => ['app']]));

    CompleteBackupAction::run($snapshot, new BackupResult('app.sql.gz', 2048, 'abc123', [
        new VolumeTransferResult($snapshot->files->first()->volume_id, 'R2 Bucket', SnapshotFileStatus::Completed, storageWarning: 'Storage limit reached for volume "R2 Bucket".'),
    ]));

    Notification::assertSentTimes(StorageLimitWarningNotification::class, 1);
});
