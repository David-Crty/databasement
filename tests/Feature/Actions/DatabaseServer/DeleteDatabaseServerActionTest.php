<?php

use App\Actions\DatabaseServer\DeleteDatabaseServerAction;
use App\Exceptions\DatabaseServer\DatabaseServerFilesNotDeletedException;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;

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

test('it deletes the restores that targeted the server from another server\'s snapshot', function () {
    $server = DatabaseServer::factory()->create();
    $restore = Restore::factory()->create(['target_server_id' => $server->id]);

    DeleteDatabaseServerAction::run($server);

    expect(Restore::find($restore->id))->toBeNull()
        ->and(BackupJob::find($restore->backup_job_id))->toBeNull()
        ->and(Snapshot::find($restore->snapshot_id))->not->toBeNull();
});

test('it keeps the server and its snapshots when a file cannot be deleted, and records why', function () {
    $reachable = Volume::factory()->local()->create();
    $unreachable = Volume::factory()->local()->create(['name' => 'Offsite']);
    $server = DatabaseServer::factory()->create();
    $snapshot = Snapshot::factory()->forServer($server)->onVolumes($reachable, $unreachable)->withFile()->create();

    $realProvider = app(FilesystemProvider::class);
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andReturnUsing(
        fn (Volume $volume) => $volume->is($unreachable)
            ? throw new RuntimeException('Volume unreachable')
            : $realProvider->getForVolume($volume),
    );
    app()->instance(FilesystemProvider::class, $provider);

    expect(fn () => DeleteDatabaseServerAction::run($server))
        ->toThrow(DatabaseServerFilesNotDeletedException::class, 'Offsite: Volume unreachable');

    expect(DatabaseServer::find($server->id))->not->toBeNull()
        ->and(Snapshot::find($snapshot->id))->not->toBeNull()
        ->and(SnapshotFile::where('volume_id', $unreachable->id)->value('error'))->toBe('Volume unreachable')
        ->and(SnapshotFile::where('volume_id', $reachable->id)->value('file_exists'))->toBeFalse()
        ->and(SnapshotFile::where('volume_id', $unreachable->id)->value('file_exists'))->toBeTrue();
});

test('it keeps the server when a backup completes while its files are being deleted', function () {
    $volume = Volume::factory()->local()->create();
    $server = DatabaseServer::factory()->create();
    Snapshot::factory()->forServer($server)->onVolumes($volume)->withFile()->create();

    $realProvider = app(FilesystemProvider::class);
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andReturnUsing(function (Volume $volume) use ($realProvider, $server) {
        Snapshot::factory()->forServer($server)->onVolumes($volume)->withFile()->create();

        return $realProvider->getForVolume($volume);
    });
    app()->instance(FilesystemProvider::class, $provider);

    expect(fn () => DeleteDatabaseServerAction::run($server))
        ->toThrow(DatabaseServerFilesNotDeletedException::class, 'a backup completed while it was being deleted');

    expect(DatabaseServer::find($server->id))->not->toBeNull()
        ->and($server->snapshots()->count())->toBe(2);
});
