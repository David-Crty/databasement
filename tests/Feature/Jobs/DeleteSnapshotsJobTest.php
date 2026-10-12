<?php

use App\Actions\Snapshot\DeleteSnapshotAction;
use App\Jobs\DeleteSnapshotsJob;
use App\Models\Snapshot;

test('deletes the snapshots marked as deleting', function () {
    $snapshot = Snapshot::factory()->withFile()->create(['deleting' => true]);

    (new DeleteSnapshotsJob([$snapshot->id]))->handle();

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('a snapshot whose deletion fails leaves the deleting state, without stopping the others', function () {
    $failing = Snapshot::factory()->withFile()->create(['deleting' => true]);
    $other = Snapshot::factory()->withFile()->create(['deleting' => true]);

    DeleteSnapshotAction::partialMock()->shouldReceive('handle')
        ->withArgs(fn (Snapshot $snapshot) => $snapshot->is($failing))
        ->andThrow(new RuntimeException('Volume unreachable'));

    (new DeleteSnapshotsJob([$failing->id, $other->id]))->handle();

    expect($failing->fresh())->not->toBeNull()
        ->deleting->toBeFalse()
        ->and(Snapshot::find($other->id))->toBeNull();
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
