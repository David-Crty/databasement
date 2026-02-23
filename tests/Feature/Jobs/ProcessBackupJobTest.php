<?php

use App\Contracts\BackupLogger;
use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('job is configured with correct queue and settings', function () {
    AppConfig::set('backup.job_timeout', 5400);
    AppConfig::set('backup.job_tries', 5);
    AppConfig::set('backup.job_backoff', 120);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($server, 'manual')[0];

    $job = new ProcessBackupJob($snapshot->id);

    expect($job->queue)->toBe('backups')
        ->and($job->timeout)->toBe(5400)
        ->and($job->tries)->toBe(5)
        ->and($job->backoff)->toBe(120);
});

test('handle builds config from models and updates snapshot on success', function () {
    Log::spy();

    $server = createDatabaseServer([
        'name' => 'Production MySQL',
        'host' => 'db.example.com',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($server, 'manual')[0];

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (BackupConfig $config) => $config->databaseName === 'myapp'
                && $config->database->host === 'db.example.com'
                && $config->database->port === 3306
                && $config->database->username === 'root'
                && $config->volume->name === $snapshot->volume->name
                && str_contains($config->workingDirectory, 'backup-')
            ),
            Mockery::type(BackupLogger::class),
        )
        ->andReturn(new BackupResult('prod-myapp-2024.sql.gz', 2048, 'abc123def456'));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe('completed')
        ->and($snapshot->filename)->toBe('prod-myapp-2024.sql.gz')
        ->and($snapshot->file_size)->toBe(2048)
        ->and($snapshot->checksum)->toBe('abc123def456')
        ->and($snapshot->file_verified_at)->not->toBeNull();
});

test('handle passes backup path from model to config', function () {
    $server = createDatabaseServer([
        'name' => 'MySQL Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'database_names' => ['myapp'],
    ]);
    $server->backup->update(['path' => 'mysql/production']);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($server, 'manual')[0];

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (BackupConfig $config) => $config->backupPath === 'mysql/production'),
            Mockery::type(BackupLogger::class),
        )
        ->andReturn(new BackupResult('test.sql.gz', 100, 'checksum'));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);
});

test('handle defaults backup path to empty string when null', function () {
    $server = createDatabaseServer([
        'name' => 'MySQL Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'database_names' => ['myapp'],
    ]);
    $server->backup->update(['path' => null]);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($server, 'manual')[0];

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (BackupConfig $config) => $config->backupPath === ''),
            Mockery::type(BackupLogger::class),
        )
        ->andReturn(new BackupResult('test.sql.gz', 100, 'checksum'));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);
});

test('handle marks job as failed and re-throws on execute failure', function () {
    $server = createDatabaseServer([
        'name' => 'Production MySQL',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'database_names' => ['myapp'],
    ]);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($server, 'manual')[0];

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Access denied for user'));

    expect(fn () => (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask))
        ->toThrow(\App\Exceptions\ShellProcessFailed::class, 'Access denied for user');

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe('failed')
        ->and($snapshot->job->error_message)->toBe('Access denied for user')
        ->and($snapshot->job->completed_at)->not->toBeNull();
});

test('job can be dispatched to queue', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($server, 'manual')[0];

    ProcessBackupJob::dispatch($snapshot->id);

    Queue::assertPushedOn('backups', ProcessBackupJob::class, function ($job) use ($snapshot) {
        return $job->snapshotId === $snapshot->id;
    });
});

test('failed method sends notification', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($server, 'manual')[0];

    $job = new ProcessBackupJob($snapshot->id);
    $exception = new \Exception('Backup failed: connection timeout');

    $job->failed($exception);

    Notification::assertSentOnDemand(
        \App\Notifications\BackupFailedNotification::class,
        fn ($notification) => $notification->snapshot->id === $snapshot->id
            && $notification->exception->getMessage() === 'Backup failed: connection timeout'
    );
});
