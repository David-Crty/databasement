<?php

use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('job is configured with correct queue and settings', function () {
    AppConfig::set('backup.job_timeout', 5400);
    AppConfig::set('backup.job_tries', 5);
    AppConfig::set('backup.job_backoff', 120);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    $job = new ProcessBackupJob($snapshot->id);

    expect($job->queue)->toBe('backups')
        ->and($job->timeout)->toBe(5400)
        ->and($job->tries)->toBe(5)
        ->and($job->backoff)->toBe(120);
});

test('job calls BackupTask run method', function () {
    Log::spy();

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    // Mock BackupTask to avoid actual backup execution
    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('run')
        ->once()
        ->with(
            Mockery::on(fn ($s) => $s->id === $snapshot->id),
            Mockery::type('int'),  // attempt
            Mockery::type('int')   // maxAttempts
        );

    app()->instance(BackupTask::class, $mockBackupTask);

    // Dispatch and process the job synchronously
    ProcessBackupJob::dispatchSync($snapshot->id);

    // Verify log was called
    Log::shouldHaveReceived('info')
        ->with('Backup completed successfully', Mockery::type('array'));
});

test('job can be dispatched to queue', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    ProcessBackupJob::dispatch($snapshot->id);

    Queue::assertPushedOn('backups', ProcessBackupJob::class, function ($job) use ($snapshot) {
        return $job->snapshotId === $snapshot->id;
    });
});

test('failed method sends notification', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    $job = new ProcessBackupJob($snapshot->id);
    $exception = new \Exception('Backup failed: connection timeout');

    // Call the failed method (simulates Laravel queue calling this after all retries)
    $job->failed($exception);

    // Verify notification was sent
    Notification::assertSentOnDemand(
        \App\Notifications\BackupFailedNotification::class,
        fn ($notification) => $notification->snapshot->id === $snapshot->id
            && $notification->exception->getMessage() === 'Backup failed: connection timeout'
    );
});
