<?php

use App\Actions\Restore\CompleteRestoreAction;
use App\Enums\BackupJobStatus;
use App\Models\NotificationChannel;
use App\Models\Restore;
use App\Notifications\RestoreSuccessNotification;
use Illuminate\Support\Facades\Notification;

test('it completes the job and notifies the success', function () {
    NotificationChannel::factory()->email()->create();
    $restore = Restore::factory()->withStatus('running')->create();
    $restore->targetServer->update(['notification_trigger' => 'all']);

    CompleteRestoreAction::run($restore);

    expect($restore->job->fresh()->status)->toBe(BackupJobStatus::Completed);
    Notification::assertSentTimes(RestoreSuccessNotification::class, 1);
});
