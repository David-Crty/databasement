<?php

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DTO\RestoreConfig;
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

test('handle builds config from models and marks job completed', function () {
    Log::spy();

    $sourceServer = createDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'source.localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createDatabaseServer([
        'name' => 'Target MySQL',
        'host' => 'target.localhost',
        'port' => 3307,
        'database_type' => 'mysql',
        'username' => 'admin',
        'password' => 'targetpass',
        'database_names' => ['targetdb'],
    ]);

    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($sourceServer, 'manual')[0];
    $snapshot->update(['filename' => 'backup.sql.gz', 'file_size' => 2048, 'compression_type' => CompressionType::GZIP]);
    $snapshot->job->markCompleted();

    $restore = $factory->createRestore($snapshot, $targetServer, 'restored_db');

    $mockRestoreTask = Mockery::mock(RestoreTask::class);
    $mockRestoreTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (RestoreConfig $config) => $config->targetServer->host === 'target.localhost'
                && $config->targetServer->port === 3307
                && $config->targetServer->username === 'admin'
                && $config->snapshotFilename === 'backup.sql.gz'
                && $config->snapshotFileSize === 2048
                && $config->snapshotDatabaseName === 'sourcedb'
                && $config->schemaName === 'restored_db'
                && $config->snapshotVolume->name === $snapshot->volume->name
                && str_contains($config->workingDirectory, 'restore-')
            ),
            Mockery::any(), // BackupLogger (the job itself)
        );

    (new ProcessRestoreJob($restore->id))->handle($mockRestoreTask);

    $restore->refresh();
    expect($restore->job->status)->toBe('completed');
});

test('handle marks job as failed and re-throws on execute failure', function () {
    $sourceServer = createDatabaseServer([
        'name' => 'Source MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'database_names' => ['sourcedb'],
    ]);

    $targetServer = createDatabaseServer([
        'name' => 'Target MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'database_names' => ['targetdb'],
    ]);

    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($sourceServer, 'manual')[0];
    $snapshot->update(['filename' => 'backup.sql.gz', 'compression_type' => CompressionType::GZIP]);
    $snapshot->job->markCompleted();

    $restore = $factory->createRestore($snapshot, $targetServer, 'restored_db');

    $mockRestoreTask = Mockery::mock(RestoreTask::class);
    $mockRestoreTask->shouldReceive('execute')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Access denied for user'));

    expect(fn () => (new ProcessRestoreJob($restore->id))->handle($mockRestoreTask))
        ->toThrow(\App\Exceptions\ShellProcessFailed::class, 'Access denied for user');

    $restore->refresh();
    expect($restore->job->status)->toBe('failed')
        ->and($restore->job->error_message)->toBe('Access denied for user')
        ->and($restore->job->completed_at)->not->toBeNull();
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

    $job->failed($exception);

    Notification::assertSentOnDemand(
        \App\Notifications\RestoreFailedNotification::class,
        fn ($notification) => $notification->restore->id === $restore->id
            && $notification->exception->getMessage() === 'Restore failed: access denied'
    );
});
