<?php

use App\Actions\Volume\DeleteVolumeAction;
use App\Exceptions\Volume\VolumeFilesNotDeletedException;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;

test('it deletes the volume with its snapshots and their files', function () {
    $volume = Volume::factory()->local()->create();
    $snapshot = Snapshot::factory()->onVolumes($volume)->withFile()->create();
    $filePath = $volume->config['path'].'/'.$snapshot->filename;

    DeleteVolumeAction::run($volume);

    expect(Volume::find($volume->id))->toBeNull()
        ->and(Snapshot::find($snapshot->id))->toBeNull()
        ->and(file_exists($filePath))->toBeFalse();
});

test('it can leave the files on the storage', function () {
    $volume = Volume::factory()->local()->create();
    $snapshot = Snapshot::factory()->onVolumes($volume)->withFile()->create();
    $filePath = $volume->config['path'].'/'.$snapshot->filename;

    DeleteVolumeAction::run($volume, keepFiles: true);

    expect(Volume::find($volume->id))->toBeNull()
        ->and(file_exists($filePath))->toBeTrue();
});

test('it deletes the restores of its snapshots and the backup configurations that only targeted it', function () {
    $volume = Volume::factory()->local()->create();
    $server = DatabaseServer::factory()->create(['database_names' => ['app']]);
    $backup = $server->backups->first();
    $backup->volumes()->sync([$volume->id]);
    $snapshot = Snapshot::factory()->forServer($server)->onVolumes($volume)->withFile()->create(['backup_id' => $backup->id]);
    $restore = Restore::factory()->create(['snapshot_id' => $snapshot->id]);

    DeleteVolumeAction::run($volume);

    expect(Restore::find($restore->id))->toBeNull()
        ->and(BackupJob::find($restore->backup_job_id))->toBeNull()
        ->and(Backup::find($backup->id))->toBeNull();
});

test('a snapshot with a copy on another volume survives without its copy on this one', function () {
    $volume = Volume::factory()->local()->create();
    $other = Volume::factory()->local()->create();
    $snapshot = Snapshot::factory()->onVolumes($volume, $other)->withFile()->create();

    DeleteVolumeAction::run($volume);

    expect(Snapshot::find($snapshot->id))->not->toBeNull()
        ->and($snapshot->files()->pluck('volume_id')->all())->toBe([$other->id]);
});

test('it keeps the volume and its snapshots when a file cannot be deleted, and records why', function () {
    $volume = Volume::factory()->local()->create();
    $snapshot = Snapshot::factory()->onVolumes($volume)->withFile()->create();

    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andThrow(new RuntimeException('Volume unreachable'));
    app()->instance(FilesystemProvider::class, $provider);

    expect(fn () => DeleteVolumeAction::run($volume))->toThrow(VolumeFilesNotDeletedException::class);

    expect(Volume::find($volume->id))->not->toBeNull()
        ->and(Snapshot::find($snapshot->id))->not->toBeNull()
        ->and(SnapshotFile::where('snapshot_id', $snapshot->id)->value('error'))->toBe('Volume unreachable');
});

test('it keeps the volume when a backup completes on it while its files are being deleted', function () {
    $volume = Volume::factory()->local()->create();
    Snapshot::factory()->onVolumes($volume)->withFile()->create();

    $realProvider = app(FilesystemProvider::class);
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andReturnUsing(function (Volume $volume) use ($realProvider) {
        Snapshot::factory()->onVolumes($volume)->withFile()->create();

        return $realProvider->getForVolume($volume);
    });
    app()->instance(FilesystemProvider::class, $provider);

    expect(fn () => DeleteVolumeAction::run($volume))
        ->toThrow(VolumeFilesNotDeletedException::class, 'a backup completed while it was being deleted');

    expect(Volume::find($volume->id))->not->toBeNull()
        ->and($volume->snapshotFiles()->count())->toBe(2);
});
