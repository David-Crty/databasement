<?php

use App\Facades\AppConfig;
use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\RestoreTask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('job is configured with correct queue and settings', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $snapshot->job->markCompleted();

    $restore = $factory->createRestore($snapshot, $server, 'restored_db');

    $job = new ProcessRestoreJob($restore->id);

    expect($job->queue)->toBe('backups')
        ->and($job->timeout)->toBe(AppConfig::get('backup.job_timeout'))
        ->and($job->tries)->toBe(AppConfig::get('backup.job_tries'))
        ->and($job->backoff)->toBe(AppConfig::get('backup.job_backoff'));
});

test('job calls RestoreTask run method', function () {
    Log::spy();

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $snapshot->job->markCompleted();

    $restore = $factory->createRestore($snapshot, $server, 'restored_db');

    // Mock RestoreTask to avoid actual restore execution
    $mockRestoreTask = Mockery::mock(RestoreTask::class);
    $mockRestoreTask->shouldReceive('run')
        ->once()
        ->with(
            Mockery::on(fn ($r) => $r->id === $restore->id),
            Mockery::type('int'),  // attempt
            Mockery::type('int')   // maxAttempts
        );

    app()->instance(RestoreTask::class, $mockRestoreTask);

    // Dispatch and process the job synchronously
    ProcessRestoreJob::dispatchSync($restore->id);

    // Verify log was called
    Log::shouldHaveReceived('info')
        ->with('Restore completed successfully', Mockery::type('array'));
});

test('job can be dispatched to queue', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $snapshot->job->markCompleted();

    $restore = $factory->createRestore($snapshot, $server, 'restored_db');

    ProcessRestoreJob::dispatch($restore->id);

    Queue::assertPushedOn('backups', ProcessRestoreJob::class, function ($job) use ($restore) {
        return $job->restoreId === $restore->id;
    });
});

test('failed method sends notification', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];
    $snapshot->job->markCompleted();

    $restore = $factory->createRestore($snapshot, $server, 'restored_db');

    $job = new ProcessRestoreJob($restore->id);
    $exception = new \Exception('Restore failed: access denied');

    // Call the failed method (simulates Laravel queue calling this after all retries)
    $job->failed($exception);

    // Verify notification was sent
    Notification::assertSentOnDemand(
        \App\Notifications\RestoreFailedNotification::class,
        fn ($notification) => $notification->restore->id === $restore->id
            && $notification->exception->getMessage() === 'Restore failed: access denied'
    );
});
