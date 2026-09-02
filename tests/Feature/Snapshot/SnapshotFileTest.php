<?php

use App\Enums\SnapshotFileStatus;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;

test('deleteFromVolume reports failure instead of throwing when the volume is unreachable', function () {
    Log::spy();

    $snapshot = Snapshot::factory()->create(['filename' => 'backup.sql.gz']);
    $file = $snapshot->files()->firstOrFail();

    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')
        ->once()
        ->andThrow(new RuntimeException('SFTP unreachable'));
    app()->instance(FilesystemProvider::class, $provider);

    expect($file->deleteFromVolume())->toBeFalse();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'Failed to delete backup file for snapshot'
            && $context['error'] === 'SFTP unreachable'
            && $context['volume_id'] === $file->volume_id);
});

test('deleteFromVolume returns false when the copy was never uploaded', function () {
    $snapshot = Snapshot::factory()->create(['filename' => '']);
    $file = $snapshot->files()->firstOrFail();

    // No filesystem should be resolved at all for a copy with no archive.
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldNotReceive('getForVolume');
    app()->instance(FilesystemProvider::class, $provider);

    expect($file->deleteFromVolume())->toBeFalse();
});

test('a volume that fails to delete does not stop the snapshot\'s other copies', function () {
    Log::spy();

    $brokenVolume = Volume::factory()->local()->create(['name' => 'Broken']);
    $healthyVolume = Volume::factory()->local()->create(['name' => 'Healthy']);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $server->backups->first()->volumes()->sync([$brokenVolume->id, $healthyVolume->id]);

    $snapshot = app(BackupJobFactory::class)
        ->createSnapshots($server->backups->first()->fresh(), 'manual')[0];
    $snapshot->update(['filename' => 'backup.sql.gz']);
    $snapshot->files()->update(['status' => SnapshotFileStatus::Completed]);

    $healthyFilesystem = Mockery::mock(Filesystem::class);
    $healthyFilesystem->shouldReceive('fileExists')->with('backup.sql.gz')->once()->andReturnTrue();
    $healthyFilesystem->shouldReceive('delete')->with('backup.sql.gz')->once();

    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')
        ->twice()
        ->andReturnUsing(function (Volume $volume) use ($brokenVolume, $healthyFilesystem) {
            if ($volume->id === $brokenVolume->id) {
                throw new RuntimeException('Volume unreachable');
            }

            return $healthyFilesystem;
        });
    app()->instance(FilesystemProvider::class, $provider);

    // True because the healthy copy was removed despite the other volume failing.
    expect($snapshot->deleteBackupFiles())->toBeTrue();

    Log::shouldHaveReceived('error')->once();
});

test('deleting a snapshot deletes its backup files from the volume', function () {
    $snapshot = Snapshot::factory()->create(['filename' => 'backup.sql.gz']);

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('fileExists')->with('backup.sql.gz')->once()->andReturnTrue();
    $filesystem->shouldReceive('delete')->with('backup.sql.gz')->once();

    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->once()->andReturn($filesystem);
    app()->instance(FilesystemProvider::class, $provider);

    $snapshot->delete();

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('backup files are not deleted from the volume when the delete transaction rolls back', function () {
    // Deleting from the volume is irreversible, so it must be deferred until the
    // transaction wrapping the snapshot delete is guaranteed to commit. If a later
    // step in that transaction fails and rolls back, the row survives and the
    // files it references must still be there.
    $snapshot = Snapshot::factory()->create(['filename' => 'backup.sql.gz']);

    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldNotReceive('getForVolume');
    app()->instance(FilesystemProvider::class, $provider);

    try {
        DB::transaction(function () use ($snapshot) {
            $snapshot->delete();

            throw new RuntimeException('simulated failure after delete');
        });
    } catch (RuntimeException) {
        // Expected: proves the deferred file deletion never ran.
    }

    expect(Snapshot::find($snapshot->id))->not->toBeNull();
});
