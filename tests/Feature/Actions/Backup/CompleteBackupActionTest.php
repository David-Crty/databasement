<?php

use App\Actions\Backup\CompleteBackupAction;
use App\Enums\BackupJobStatus;
use App\Models\NotificationChannel;
use App\Notifications\BackupSuccessNotification;
use Illuminate\Support\Facades\Notification;

test('it completes the job and notifies the success', function () {
    NotificationChannel::factory()->email()->create();
    $snapshot = pendingSnapshot(createDatabaseServer(['database_names' => ['app'], 'notification_trigger' => 'all']));

    CompleteBackupAction::run($snapshot);

    expect($snapshot->job->fresh()->status)->toBe(BackupJobStatus::Completed);
    Notification::assertSentTimes(BackupSuccessNotification::class, 1);
});
