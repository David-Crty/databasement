<?php

use App\Jobs\DeleteSnapshotsJob;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Queue;

test('deletes the snapshots marked as deleting', function () {
    $snapshot = Snapshot::factory()->withFile()->create(['deleting' => true]);

    (new DeleteSnapshotsJob([$snapshot->id]))->handle();

    expect(Snapshot::find($snapshot->id))->toBeNull();
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
