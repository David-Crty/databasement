<?php

use App\Actions\Backup\RecordBackupResultAction;
use App\Enums\SnapshotFileStatus;
use App\Models\NotificationChannel;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Notifications\StorageLimitWarningNotification;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\VolumeTransferResult;
use Illuminate\Support\Facades\Notification;

test('it records the archive and which volumes it reached', function () {
    $uploaded = Volume::factory()->s3()->create();
    $failed = Volume::factory()->s3()->create();
    $snapshot = Snapshot::factory()->onVolumes($uploaded, $failed)->create(['checksum' => null]);

    RecordBackupResultAction::run($snapshot, new BackupResult('app.sql.gz', 2048, 'abc123', [
        new VolumeTransferResult($uploaded->id, $uploaded->name, SnapshotFileStatus::Completed),
        new VolumeTransferResult($failed->id, $failed->name, SnapshotFileStatus::Failed, 'Access Denied'),
    ]));

    $files = $snapshot->files()->get()->keyBy('volume_id');
    expect($snapshot->fresh())->filename->toBe('app.sql.gz')->file_size->toBe(2048)->checksum->toBe('abc123')
        ->and($files[$uploaded->id]->status)->toBe(SnapshotFileStatus::Completed)
        ->and($files[$failed->id])->status->toBe(SnapshotFileStatus::Failed)->error->toBe('Access Denied');
});

test('a run that produced no archive records only the volumes', function () {
    $volume = Volume::factory()->s3()->create();
    $snapshot = Snapshot::factory()->onVolumes($volume)->create(['filename' => 'earlier.sql.gz']);

    RecordBackupResultAction::run($snapshot, new BackupResult('', 0, null, [
        new VolumeTransferResult($volume->id, $volume->name, SnapshotFileStatus::Failed, 'Unreachable'),
    ]));

    expect($snapshot->fresh()->filename)->toBe('earlier.sql.gz')
        ->and($snapshot->files()->sole()->error)->toBe('Unreachable');
});

test('it warns about a volume past its notify-only storage limit even when another volume failed', function () {
    NotificationChannel::factory()->email()->create();
    $nearlyFull = Volume::factory()->s3()->create();
    $failed = Volume::factory()->s3()->create();
    $snapshot = Snapshot::factory()->onVolumes($nearlyFull, $failed)->create();

    RecordBackupResultAction::run($snapshot, new BackupResult('app.sql.gz', 2048, 'abc123', [
        new VolumeTransferResult($nearlyFull->id, $nearlyFull->name, SnapshotFileStatus::Completed, storageWarning: "Storage limit reached for volume \"{$nearlyFull->name}\"."),
        new VolumeTransferResult($failed->id, $failed->name, SnapshotFileStatus::Failed, 'Access Denied'),
    ]));

    Notification::assertSentTimes(StorageLimitWarningNotification::class, 1);
});
