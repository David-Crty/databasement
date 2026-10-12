<?php

use App\Actions\Snapshot\DeleteSnapshotAction;
use App\Enums\AgentJobType;
use App\Enums\VolumeType;
use App\Exceptions\Backup\SnapshotFilesNotDeletedException;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;
use League\Flysystem\Filesystem;

function snapshotOfAgentBackedServer(Volume ...$volumes): Snapshot
{
    $server = DatabaseServer::factory()->create(['agent_id' => Agent::factory()->create()->id]);

    return Snapshot::factory()->forServer($server)
        ->onVolumes(...($volumes ?: [Volume::factory()->s3()->create()]))
        ->withFile()
        ->create();
}

/**
 * Let the app reach only its local volumes, as when the others sit on the
 * agent's private network.
 */
function appReachesOnlyItsLocalVolumes(): void
{
    $realProvider = app(FilesystemProvider::class);
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andReturnUsing(
        fn (Volume $volume) => $volume->type === VolumeType::LOCAL->value
            ? $realProvider->getForVolume($volume)
            : throw new RuntimeException('Volume unreachable'),
    );
    app()->instance(FilesystemProvider::class, $provider);
}

test('it deletes the snapshot and its files', function () {
    $volume = Volume::factory()->local()->create();
    $snapshot = Snapshot::factory()->onVolumes($volume)->withFile()->create();
    $filePath = $volume->config['path'].'/'.$snapshot->filename;

    expect(DeleteSnapshotAction::run($snapshot))->toBeTrue()
        ->and(Snapshot::find($snapshot->id))->toBeNull()
        ->and(file_exists($filePath))->toBeFalse();
});

test('it can leave the files in place', function () {
    $volume = Volume::factory()->local()->create();
    $snapshot = Snapshot::factory()->onVolumes($volume)->withFile()->create();
    $filePath = $volume->config['path'].'/'.$snapshot->filename;

    DeleteSnapshotAction::run($snapshot, keepFiles: true);

    expect(Snapshot::find($snapshot->id))->toBeNull()
        ->and(file_exists($filePath))->toBeTrue();
});

test('a file the app can reach on an agent-backed server is deleted by the app', function () {
    $snapshot = snapshotOfAgentBackedServer();
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('fileExists')->andReturnTrue();
    $filesystem->shouldReceive('delete')->once()->with($snapshot->filename);
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andReturn($filesystem);
    app()->instance(FilesystemProvider::class, $provider);

    expect(DeleteSnapshotAction::run($snapshot))->toBeTrue()
        ->and(Snapshot::find($snapshot->id))->toBeNull()
        ->and(AgentJob::count())->toBe(0);
});

test('a file the app cannot reach is handed to the agent, keeping the snapshot until it reports', function () {
    appReachesOnlyItsLocalVolumes();
    $snapshot = snapshotOfAgentBackedServer();
    $file = $snapshot->files()->sole();

    expect(DeleteSnapshotAction::run($snapshot))->toBeFalse();

    $agentJob = AgentJob::where('snapshot_id', $snapshot->id)->sole();
    expect($snapshot->fresh()->deleting)->toBeTrue()
        ->and($agentJob->type)->toBe(AgentJobType::Cleanup)
        ->and(array_column($agentJob->payload['files'], 'id'))->toBe([$file->id])
        ->and($file->fresh()->error)->toBeNull();
});

test('a copy on the app\'s local disk is deleted by the app and only the others go to the agent', function () {
    appReachesOnlyItsLocalVolumes();
    $local = Volume::factory()->local()->create();
    $s3 = Volume::factory()->s3()->create();
    $snapshot = snapshotOfAgentBackedServer($local, $s3);

    DeleteSnapshotAction::run($snapshot);

    expect($snapshot->files()->pluck('volume_id')->all())->toBe([$s3->id])
        ->and(array_column(AgentJob::where('snapshot_id', $snapshot->id)->sole()->payload['files'], 'id'))
        ->toBe([$snapshot->files()->sole()->id]);
});

test('a local copy the app could not delete stays with the app instead of going to the agent', function () {
    $snapshot = snapshotOfAgentBackedServer(Volume::factory()->local()->create());
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andThrow(new RuntimeException('Disk full'));
    app()->instance(FilesystemProvider::class, $provider);

    expect(fn () => DeleteSnapshotAction::run($snapshot))->toThrow(SnapshotFilesNotDeletedException::class);

    expect(AgentJob::count())->toBe(0)
        ->and($snapshot->files()->sole()->error)->toBe('Disk full');
});
