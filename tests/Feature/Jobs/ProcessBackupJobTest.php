<?php

use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job is configured with correct queue and settings', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    $job = new ProcessBackupJob($snapshot->id);

    expect($job->queue)->toBe('backups')
        ->and($job->timeout)->toBe(config('backup.job_timeout'))
        ->and($job->tries)->toBe(config('backup.job_tries'))
        ->and($job->backoff)->toBe(config('backup.job_backoff'));
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
            Mockery::type('string')
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

test('failed method marks job as failed and logs error', function () {
    Log::spy();

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    // Verify job starts as pending
    expect($snapshot->job->status)->toBe('pending');

    $job = new ProcessBackupJob($snapshot->id);
    $exception = new \Exception('Backup failed: connection timeout');

    // Call the failed method directly
    $job->failed($exception);

    // Verify job is marked as failed
    $snapshot->refresh();
    expect($snapshot->job->status)->toBe('failed')
        ->and($snapshot->job->error_message)->toBe('Backup failed: connection timeout')
        ->and($snapshot->job->completed_at)->not->toBeNull();

    // Verify error was logged
    Log::shouldHaveReceived('error')
        ->with('Backup job failed', Mockery::type('array'));
});
