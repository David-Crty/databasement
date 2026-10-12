<?php

use App\Actions\Backup\FailBackupAction;
use App\Enums\BackupJobStatus;
use App\Models\NotificationChannel;
use App\Notifications\BackupFailedNotification;
use Illuminate\Support\Facades\Notification;

test('it fails the job with the reason and notifies the failure', function () {
    NotificationChannel::factory()->email()->create();
    $snapshot = pendingSnapshot(createDatabaseServer(['database_names' => ['app']]));

    FailBackupAction::run($snapshot, new RuntimeException('Disk full'));

    expect($snapshot->job->fresh())->status->toBe(BackupJobStatus::Failed)->error_message->toBe('Disk full');
    Notification::assertSentTimes(BackupFailedNotification::class, 1);
});
