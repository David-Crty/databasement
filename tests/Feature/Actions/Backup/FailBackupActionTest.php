<?php

use App\Actions\Backup\FailBackupAction;
use App\Enums\BackupJobStatus;
use App\Exceptions\Backup\JobCancelledException;
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

test('a job its own worker already failed with this exception is notified now', function () {
    NotificationChannel::factory()->email()->create();
    $snapshot = pendingSnapshot(createDatabaseServer(['database_names' => ['app']]));
    $exception = new RuntimeException('Disk full');
    $snapshot->job->markFailed($exception);

    expect(FailBackupAction::run($snapshot, $exception))->toBeTrue();
    Notification::assertSentTimes(BackupFailedNotification::class, 1);
});

test('a job something else already failed is left alone and not notified again', function () {
    NotificationChannel::factory()->email()->create();
    $snapshot = pendingSnapshot(createDatabaseServer(['database_names' => ['app']]));
    $snapshot->job->markFailed(new RuntimeException('Job timed out: stuck in running state.'));

    expect(FailBackupAction::run($snapshot, new RuntimeException('Job timed out')))->toBeFalse()
        ->and($snapshot->job->fresh()->error_message)->toBe('Job timed out: stuck in running state.');
    Notification::assertNothingSent();
});

test('a cancelled job is left alone and not notified', function () {
    NotificationChannel::factory()->email()->create();
    $snapshot = pendingSnapshot(createDatabaseServer(['database_names' => ['app']]));
    $snapshot->job->cancel('admin');

    expect(fn () => FailBackupAction::run($snapshot->fresh(), new RuntimeException('Disk full')))
        ->toThrow(JobCancelledException::class);

    expect($snapshot->job->fresh()->status)->toBe(BackupJobStatus::Cancelled);
    Notification::assertNothingSent();
});
