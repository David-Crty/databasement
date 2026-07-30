<?php

use App\Enums\SnapshotFileStatus;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Filesystems\FilesystemProvider;
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
