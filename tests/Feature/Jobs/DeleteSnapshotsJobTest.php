<?php

use App\Jobs\DeleteSnapshotsJob;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Illuminate\Support\Facades\Queue;

test('deletes the snapshots marked as deleting', function () {
    $snapshot = Snapshot::factory()->withFile()->create(['deleting' => true]);

    (new DeleteSnapshotsJob([$snapshot->id]))->handle();

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('keeps a snapshot whose file cannot be removed from a volume, tracking only that copy', function () {
    $broken = Volume::factory()->local()->create(['name' => 'Broken']);
    $healthy = Volume::factory()->local()->create(['name' => 'Healthy']);
    $snapshot = Snapshot::factory()->onVolumes($broken, $healthy)->withFile()->create(['deleting' => true]);
    $healthyFilePath = $healthy->config['path'].'/'.$snapshot->filename;

    $realProvider = app(FilesystemProvider::class);
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andReturnUsing(
        fn (Volume $volume) => $volume->id === $broken->id
            ? throw new RuntimeException('Volume unreachable')
            : $realProvider->getForVolume($volume),
    );
    app()->instance(FilesystemProvider::class, $provider);

    (new DeleteSnapshotsJob([$snapshot->id]))->handle();

    expect($snapshot->fresh())->not->toBeNull()
        ->deleting->toBeFalse()
        ->and(file_exists($healthyFilePath))->toBeFalse()
        ->and($snapshot->files()->get()->map(fn (SnapshotFile $file) => [$file->volume_id, $file->error])->all())
        ->toBe([[$broken->id, 'Volume unreachable']]);
});

test('keeps a snapshot locked after the deletion was queued and clears its deleting flag', function () {
    $snapshot = Snapshot::factory()->withFile()->create(['deleting' => true, 'locked' => true]);

    (new DeleteSnapshotsJob([$snapshot->id]))->handle();

    expect($snapshot->fresh())->not->toBeNull()
        ->deleting->toBeFalse();
});

test('a failed job clears the deleting flag of the snapshots it did not delete', function () {
    $snapshot = Snapshot::factory()->withFile()->create(['deleting' => true]);

    (new DeleteSnapshotsJob([$snapshot->id]))->failed();

    expect($snapshot->fresh()->deleting)->toBeFalse();
});

test('a large selection is queued in chunks', function () {
    Queue::fake();
    Snapshot::factory()->count(501)->create();

    $queued = DeleteSnapshotsJob::dispatchFor(Snapshot::query(), keepFiles: false);

    expect($queued)->toBe(501)
        ->and(Snapshot::where('deleting', true)->count())->toBe(501);
    Queue::assertPushed(DeleteSnapshotsJob::class, 2);
});
