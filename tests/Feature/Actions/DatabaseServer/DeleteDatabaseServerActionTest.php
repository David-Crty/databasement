<?php

use App\Actions\DatabaseServer\DeleteDatabaseServerAction;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;

test('it deletes the server with its snapshots and their files', function () {
    $volume = Volume::factory()->local()->create();
    $server = DatabaseServer::factory()->create();
    $snapshot = Snapshot::factory()->forServer($server)->onVolumes($volume)->withFile()->create();
    $filePath = $volume->config['path'].'/'.$snapshot->filename;

    DeleteDatabaseServerAction::run($server);

    expect(DatabaseServer::find($server->id))->toBeNull()
        ->and(Snapshot::find($snapshot->id))->toBeNull()
        ->and(file_exists($filePath))->toBeFalse();
});

test('it can leave the snapshot files in place', function () {
    $volume = Volume::factory()->local()->create();
    $server = DatabaseServer::factory()->create();
    $snapshot = Snapshot::factory()->forServer($server)->onVolumes($volume)->withFile()->create();
    $filePath = $volume->config['path'].'/'.$snapshot->filename;

    DeleteDatabaseServerAction::run($server, keepFiles: true);

    expect(DatabaseServer::find($server->id))->toBeNull()
        ->and(file_exists($filePath))->toBeTrue();
});
