<?php

use App\Actions\Volume\DeleteVolumeAction;
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
