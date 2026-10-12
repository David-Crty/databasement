<?php

use App\Actions\Snapshot\DispatchSnapshotDeletionAction;
use App\Jobs\DeleteSnapshotsJob;
use App\Models\Snapshot;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;

test('it marks the snapshots as deleting and queues their deletion', function () {
    Queue::fake();
    $snapshots = Snapshot::factory()->count(2)->create();

    $queued = DispatchSnapshotDeletionAction::run(Snapshot::query(), keepFiles: true);

    expect($queued)->toBe(2)
        ->and(Snapshot::where('deleting', true)->count())->toBe(2);
    Queue::assertPushed(DeleteSnapshotsJob::class, fn (DeleteSnapshotsJob $job) => $job->keepFiles
        && collect($job->snapshotIds)->sort()->values()->all() === $snapshots->pluck('id')->sort()->values()->all());
});

test('it queues one job per 500 snapshots', function () {
    Queue::fake();
    Snapshot::factory()->count(501)->create();

    expect(DispatchSnapshotDeletionAction::run(Snapshot::query()))->toBe(501);

    Queue::assertPushed(DeleteSnapshotsJob::class, 2);
});

test('it skips locked snapshots and those whose backup is still running', function () {
    Queue::fake();
    $locked = Snapshot::factory()->create(['locked' => true]);
    $running = Snapshot::factory()->create();
    $running->job->update(['status' => 'running']);

    expect(DispatchSnapshotDeletionAction::run(Snapshot::query()))->toBe(0)
        ->and($locked->fresh()->deleting)->toBeFalse()
        ->and($running->fresh()->deleting)->toBeFalse();
    Queue::assertNothingPushed();
});

test('it clears the deleting flag when the deletion cannot be queued', function () {
    $snapshot = Snapshot::factory()->create();
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('Queue down'));

    expect(fn () => DispatchSnapshotDeletionAction::run(Snapshot::query()))
        ->toThrow(RuntimeException::class, 'Queue down');

    expect($snapshot->fresh()->deleting)->toBeFalse();
});
