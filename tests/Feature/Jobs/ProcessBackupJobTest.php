<?php

use App\Contracts\BackupLogger;
use App\Enums\BackupJobStatus;
use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\VolumeTransferException;
use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\VolumeTransferResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('job is configured with correct queue and settings', function () {
    AppConfig::set('backup.job_timeout', 5400);
    AppConfig::set('backup.job_tries', 5);
    AppConfig::set('backup.job_backoff', 120);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

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

    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (BackupConfig $config) => $config->databaseName === 'myapp'
                && $config->database->host === 'db.example.com'
                && $config->database->port === 3306
                && $config->database->username === 'root'
                && $config->volumes[0]->name === $snapshot->files->first()->volume->name
                && str_contains($config->workingDirectory, 'backup-')
            ),
            Mockery::type(BackupLogger::class),
        )
        ->andReturn(new BackupResult('prod-myapp-2024.sql.gz', 2048, 'abc123def456'));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($snapshot->filename)->toBe('prod-myapp-2024.sql.gz')
        ->and($snapshot->file_size)->toBe(2048)
        ->and($snapshot->checksum)->toBe('abc123def456');
});

test('handle passes backup path from model to config', function () {
    $server = createDatabaseServer([
        'name' => 'MySQL Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'database_names' => ['myapp'],
    ]);
    $server->backups->first()->update(['path' => 'mysql/production']);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

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
    $server->backups->first()->update(['path' => null]);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

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

    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->andThrow(new \App\Exceptions\ShellProcessFailed('Access denied for user'));

    expect(fn () => (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask))
        ->toThrow(\App\Exceptions\ShellProcessFailed::class, 'Access denied for user');

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe(BackupJobStatus::Failed)
        ->and($snapshot->job->error_message)->toBe('Access denied for user')
        ->and($snapshot->job->completed_at)->not->toBeNull();
});

test('job can be dispatched to queue', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

    ProcessBackupJob::dispatch($snapshot->id);

    Queue::assertPushedOn('backups', ProcessBackupJob::class, function ($job) use ($snapshot) {
        return $job->snapshotId === $snapshot->id;
    });
});

test('failed method sends notification', function () {
    \App\Models\NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

    $job = new ProcessBackupJob($snapshot->id);
    $exception = new \Exception('Backup failed: connection timeout');

    $job->failed($exception);

    Notification::assertSentTimes(\App\Notifications\BackupFailedNotification::class, 1);
});

test('handle uses empty backupPath when the snapshot is orphaned (backup removed)', function () {
    $server = createDatabaseServer([
        'host' => 'db.example.com',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['myapp'],
    ]);

    $backup = $server->backups->first();
    $backup->update(['path' => 'should/not/be/used/{year}']);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($backup, 'manual')[0];

    // Delete the parent backup — FK is nullOnDelete so snapshot.backup_id → null
    $backup->delete();
    $snapshot->refresh();
    expect($snapshot->backup_id)->toBeNull();

    $capturedPath = null;
    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(function (BackupConfig $config) use (&$capturedPath) {
                $capturedPath = $config->backupPath;

                return true;
            }),
            Mockery::type(BackupLogger::class),
        )
        ->andReturn(new BackupResult('myapp.sql.gz', 1024, 'sha'));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);

    expect($capturedPath)->toBe('');
});

test('handle passes the volume used storage (completed snapshots only) to the backup config', function () {
    $server = createDatabaseServer(['database_names' => ['myapp']]);
    $backup = $server->backups->first();

    // One completed 500-byte snapshot already sits on the volume; the pending
    // snapshot being backed up must not count toward usage.
    Snapshot::factory()->forServer($server)->create(['file_size' => 500]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($backup, 'manual')[0];

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (BackupConfig $config) => $config->volumes[0]->usedBytes === 500),
            Mockery::type(BackupLogger::class),
        )
        ->andReturn(new BackupResult('myapp.sql.gz', 2048, 'abc123'));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);

    expect($snapshot->fresh()->job->status)->toBe(BackupJobStatus::Completed);
});

test('handle fails without retry when the volume storage limit is exceeded', function () {
    $server = createDatabaseServer(['database_names' => ['myapp']]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

    $quotaResult = new BackupResult('myapp.sql.gz', 2048, 'abc123', [
        new VolumeTransferResult(
            volumeId: $snapshot->files->first()->volume_id,
            volumeName: 'R2 Bucket',
            status: SnapshotFileStatus::Failed,
            error: 'Storage limit reached for volume "R2 Bucket".',
            quotaExceeded: true,
        ),
    ]);

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->andThrow(new VolumeTransferException($quotaResult, 'Storage limit reached for volume "R2 Bucket".'));

    // Unlike an ordinary failure, the quota failure is not re-thrown — that is
    // what stops the queue from retrying a backup that can never fit.
    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe(BackupJobStatus::Failed)
        ->and($snapshot->job->error_message)->toBe('Storage limit reached for volume "R2 Bucket".')
        ->and($snapshot->job->completed_at)->not->toBeNull();
});

test('handle completes and notifies all channels when the limit is exceeded in notify-only mode', function () {
    \App\Models\NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $server = createDatabaseServer(['database_names' => ['myapp']]);
    $snapshot = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0];

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->andReturn(new BackupResult('myapp.sql.gz', 2048, 'abc123', [
            new VolumeTransferResult(
                volumeId: $snapshot->files->first()->volume_id,
                volumeName: 'R2 Bucket',
                status: SnapshotFileStatus::Completed,
                storageWarning: 'Storage limit reached for volume "R2 Bucket".',
            ),
        ]));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);

    expect($snapshot->fresh()->job->status)->toBe(BackupJobStatus::Completed);
    Notification::assertSentTimes(\App\Notifications\StorageLimitWarningNotification::class, 1);
});

test('handle uploads once and records a completed file row per target volume', function () {
    $server = createDatabaseServer(['database_names' => ['myapp']]);
    $backup = $server->backups->first();
    $secondVolume = \App\Models\Volume::factory()->local()->create();
    $backup->volumes()->attach($secondVolume);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($backup->fresh(), 'manual')[0];
    expect($snapshot->files)->toHaveCount(2);

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (BackupConfig $config) => count($config->volumes) === 2),
            Mockery::type(BackupLogger::class),
        )
        ->andReturnUsing(fn (BackupConfig $config) => new BackupResult('myapp.sql.gz', 2048, 'abc123', array_map(
            fn ($volume) => new VolumeTransferResult(
                volumeId: $volume->id,
                volumeName: $volume->name,
                status: SnapshotFileStatus::Completed,
            ),
            $config->volumes,
        )));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($snapshot->files()->completed()->count())->toBe(2)
        ->and($snapshot->filename)->toBe('myapp.sql.gz')
        ->and($snapshot->hasExistingFile())->toBeTrue();
});

test('handle records the successful copy and fails the job when one upload fails', function () {
    $server = createDatabaseServer(['database_names' => ['myapp']]);
    $backup = $server->backups->first();
    $secondVolume = \App\Models\Volume::factory()->local()->create();
    $backup->volumes()->attach($secondVolume);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($backup->fresh(), 'manual')[0];
    [$goodFile, $badFile] = [$snapshot->files[0], $snapshot->files[1]];

    $result = new BackupResult('myapp.sql.gz', 2048, 'abc123', [
        new VolumeTransferResult(volumeId: $goodFile->volume_id, volumeName: 'good', status: SnapshotFileStatus::Completed),
        new VolumeTransferResult(volumeId: $badFile->volume_id, volumeName: 'bad', status: SnapshotFileStatus::Failed, error: 'S3 unreachable'),
    ]);

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->andThrow(new VolumeTransferException($result, 'Upload failed for volume(s): bad'));

    expect(fn () => (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask))
        ->toThrow(VolumeTransferException::class);

    $snapshot->refresh();
    expect($snapshot->job->status)->toBe(BackupJobStatus::Failed)
        ->and($goodFile->fresh()->status)->toBe(SnapshotFileStatus::Completed)
        ->and($snapshot->filename)->toBe('myapp.sql.gz')
        ->and($badFile->fresh()->status)->toBe(SnapshotFileStatus::Failed)
        ->and($badFile->fresh()->error)->toBe('S3 unreachable')
        ->and($snapshot->hasExistingFile())->toBeTrue();
});

test('handle retries only the copies that have not completed yet', function () {
    $server = createDatabaseServer(['database_names' => ['myapp']]);
    $backup = $server->backups->first();
    $secondVolume = \App\Models\Volume::factory()->local()->create();
    $backup->volumes()->attach($secondVolume);

    $snapshot = app(BackupJobFactory::class)->createSnapshots($backup->fresh(), 'manual')[0];
    [$doneFile, $pendingFile] = [$snapshot->files[0], $snapshot->files[1]];
    $doneFile->update(['status' => SnapshotFileStatus::Completed, 'filename' => 'myapp.sql.gz']);

    $mockBackupTask = Mockery::mock(BackupTask::class);
    $mockBackupTask->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn (BackupConfig $config) => count($config->volumes) === 1
                && $config->volumes[0]->id === $pendingFile->volume_id),
            Mockery::type(BackupLogger::class),
        )
        ->andReturn(new BackupResult('myapp.sql.gz', 2048, 'abc123', [
            new VolumeTransferResult(volumeId: $pendingFile->volume_id, volumeName: 'v', status: SnapshotFileStatus::Completed),
        ]));

    (new ProcessBackupJob($snapshot->id))->handle($mockBackupTask);

    expect($snapshot->fresh()->files()->completed()->count())->toBe(2);
});
