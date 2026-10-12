<?php

use App\Actions\Restore\FailRestoreAction;
use App\Enums\BackupJobStatus;
use App\Models\NotificationChannel;
use App\Models\Restore;
use App\Notifications\RestoreFailedNotification;
use Illuminate\Support\Facades\Notification;

test('it fails the job with the reason and notifies the failure', function () {
    NotificationChannel::factory()->email()->create();
    $restore = Restore::factory()->withStatus('running')->create();

    FailRestoreAction::run($restore, new RuntimeException('Target unreachable'));

    expect($restore->job->fresh())->status->toBe(BackupJobStatus::Failed)->error_message->toBe('Target unreachable');
    Notification::assertSentTimes(RestoreFailedNotification::class, 1);
});
