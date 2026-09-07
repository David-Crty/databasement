<?php

use App\Enums\BackupJobStatus;
use App\Enums\RunKind;
use App\Enums\SnapshotFileStatus;
use App\Jobs\ProcessBackupJob;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\S3Database;
use App\Services\Backup\DTO\VolumeTransferResult;
use App\Services\Backup\S3BucketBackupEngine;
use Illuminate\Support\Facades\Notification;

function makeS3Outcome(array $volumeResults, array $objectFiles, string $checksum): array
{
    return [
        'run_kind' => RunKind::FULL,
        'full_snapshot_id' => null,
        'filename' => 'photos-20260907-091200.s3.full.gz',
        'file_size' => 2048,
        'checksum' => $checksum,
        'volume_results' => $volumeResults,
        'object_files' => $objectFiles,
        'object_state' => ['a.txt' => ['size' => 1, 'mtime' => 2]],
    ];
}

test('bucket run retries re-upload the regenerated archive to every volume and keep object rows idempotent', function () {
    $server = DatabaseServer::factory()->s3()->create();
    $backup = $server->backups()->oldest('id')->firstOrFail();
    $volA = Volume::factory()->local()->create();
    $volB = Volume::factory()->local()->create();
    // The default backup may already carry a volume; target exactly these two.
    $backup->volumes()->detach();
    $backup->volumes()->attach([$volA->id, $volB->id]);

    $jobRow = BackupJob::create(['status' => BackupJobStatus::Pending]);
    $snapshot = Snapshot::factory()
        ->forServer($server)
        ->create([
            'backup_id' => $backup->id,
            'backup_job_id' => $jobRow->id,
            'database_name' => 'photos',
            'filename' => '',
            'method' => 'manual',
        ]);

    $fileA = $snapshot->files()->where('volume_id', $volA->id)->firstOrFail();
    $fileB = $snapshot->files()->where('volume_id', $volB->id)->firstOrFail();

    $s3Handler = Mockery::mock(S3Database::class);
    $s3Handler->shouldReceive('getFilesystem')
        ->andReturn(new \League\Flysystem\Filesystem(
            new \League\Flysystem\Local\LocalFilesystemAdapter(sys_get_temp_dir())
        ));

    $provider = Mockery::mock(DatabaseProvider::class);
    $provider->shouldReceive('makeForServer')
        ->andReturn($s3Handler);
    $this->app->instance(DatabaseProvider::class, $provider);

    // The engine regenerates the archive from the live bucket on every attempt
    // (attempt 2 can therefore differ from attempt 1). Each attempt must upload
    // to EVERY volume — including the one that already completed — so all
    // copies converge on the archive the final attempt persists.
    $engine = Mockery::mock(S3BucketBackupEngine::class);
    $engine->shouldReceive('run')
        ->twice()
        ->with(
            Mockery::type(Snapshot::class),
            Mockery::type(\League\Flysystem\Filesystem::class),
            'photos',
            Mockery::on(function (array $targets) use ($volA, $volB) {
                // The retry must target exactly the two configured volumes —
                // not a count of two that could hide a duplicate of one volume.
                $ids = array_map(fn ($target) => $target->id, $targets);
                sort($ids);

                return $ids === [$volA->id, $volB->id];
            }),
            Mockery::type(\App\Contracts\BackupLogger::class),
        )
        ->andReturn(
            makeS3Outcome(
                volumeResults: [
                    new VolumeTransferResult(volumeId: $fileA->volume_id, volumeName: 'A', status: SnapshotFileStatus::Completed),
                    new VolumeTransferResult(volumeId: $fileB->volume_id, volumeName: 'B', status: SnapshotFileStatus::Failed, error: 'S3 unreachable'),
                ],
                objectFiles: [
                    ['path' => 'a.txt', 'size' => 1, 'mtime' => null, 'checksum' => null, 'tombstone' => false],
                    ['path' => 'b.txt', 'size' => 2, 'mtime' => null, 'checksum' => null, 'tombstone' => false],
                ],
                checksum: 'attempt-1',
            ),
            makeS3Outcome(
                volumeResults: [
                    new VolumeTransferResult(volumeId: $fileA->volume_id, volumeName: 'A', status: SnapshotFileStatus::Completed, storageWarning: 'Storage limit reached for volume "A".'),
                    new VolumeTransferResult(volumeId: $fileB->volume_id, volumeName: 'B', status: SnapshotFileStatus::Completed),
                ],
                // The bucket changed between attempts: the retry's archive only
                // carries b.txt, so the object rows must be replaced, not appended.
                objectFiles: [
                    ['path' => 'b.txt', 'size' => 3, 'mtime' => null, 'checksum' => null, 'tombstone' => false],
                ],
                checksum: 'attempt-2',
            ),
        );
    $this->app->instance(S3BucketBackupEngine::class, $engine);

    Notification::fake();
    \App\Models\NotificationChannel::factory()->email()->create(['config' => ['to' => 'admin@example.com']]);

    $backupTask = app(\App\Services\Backup\BackupTask::class);

    // Attempt 1: volume B fails after volume A succeeded.
    expect(fn () => (new ProcessBackupJob($snapshot->id))->handle($backupTask))
        ->toThrow(\RuntimeException::class);

    expect($jobRow->fresh()->status)->toBe(BackupJobStatus::Failed)
        ->and($fileA->fresh()->status)->toBe(SnapshotFileStatus::Completed)
        ->and($fileB->fresh()->status)->toBe(SnapshotFileStatus::Failed)
        ->and($snapshot->fresh()->checksum)->toBe('attempt-1')
        ->and($snapshot->fresh()->objectFiles()->count())->toBe(2);

    // Attempt 2 (queue retry): the archive is regenerated and uploaded to BOTH
    // volumes again; completed volume A is overwritten with the new archive.
    (new ProcessBackupJob($snapshot->id))->handle($backupTask);

    $snapshot->refresh();
    expect($jobRow->fresh()->status)->toBe(BackupJobStatus::Completed)
        ->and($snapshot->checksum)->toBe('attempt-2')
        ->and($fileA->fresh()->status)->toBe(SnapshotFileStatus::Completed)
        ->and($fileB->fresh()->status)->toBe(SnapshotFileStatus::Completed)
        // Replaced, not doubled: one archive of object rows for the final run.
        ->and($snapshot->objectFiles()->count())->toBe(1)
        ->and($snapshot->objectFiles()->first()->path)->toBe('b.txt');

    // Notify-only storage-limit warning is forwarded like the SQL dump path.
    Notification::assertSentTimes(\App\Notifications\StorageLimitWarningNotification::class, 1);
});
