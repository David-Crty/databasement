<?php

use App\Actions\Snapshot\FinishSnapshotDeletionAction;
use App\Exceptions\Backup\SnapshotFilesNotDeletedException;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Models\Volume;

test('it deletes the snapshot once every copy is removed', function () {
    $snapshot = Snapshot::factory()->onVolumes(Volume::factory()->s3()->create())->withFile()->create(['deleting' => true]);

    FinishSnapshotDeletionAction::run($snapshot, [$snapshot->files()->sole()->id => null]);

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('a copy that is left keeps the snapshot with its error, and only that copy', function () {
    $removed = Volume::factory()->s3()->create();
    $failed = Volume::factory()->s3()->create(['name' => 'Offsite']);
    $snapshot = Snapshot::factory()->onVolumes($removed, $failed)->withFile()->create(['deleting' => true]);
    $files = $snapshot->files()->get()->keyBy('volume_id');

    expect(fn () => FinishSnapshotDeletionAction::run($snapshot, [
        $files[$removed->id]->id => null,
        $files[$failed->id]->id => 'Access Denied',
    ]))->toThrow(SnapshotFilesNotDeletedException::class, 'Offsite: Access Denied');

    expect($snapshot->fresh())->not->toBeNull()
        ->deleting->toBeFalse()
        ->and($snapshot->files()->get()->map(fn (SnapshotFile $file) => [$file->volume_id, $file->error])->all())
        ->toBe([[$failed->id, 'Access Denied']]);
});
