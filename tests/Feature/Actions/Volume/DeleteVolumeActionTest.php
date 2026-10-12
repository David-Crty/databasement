<?php

use App\Actions\Volume\DeleteVolumeAction;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Models\Volume;

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
