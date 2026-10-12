<?php

use App\Actions\ScheduledRestore\RunScheduledRestoreAction;
use App\Jobs\ProcessRestoreJob;
use App\Models\Restore;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

test('it restores the latest snapshot with the stored options', function () {
    $scheduledRestore = createScheduledRestore(['options' => ['force_database' => true]]);
    $snapshot = Snapshot::factory()->forServer($scheduledRestore->sourceServer)->create(['database_name' => 'app']);

    $result = RunScheduledRestoreAction::run($scheduledRestore);

    expect($result->restore->snapshot_id)->toBe($snapshot->id)
        ->and($result->restore->scheduled_restore_id)->toBe($scheduledRestore->id)
        ->and($result->restore->options)->toBe(['force_database' => true])
        ->and($scheduledRestore->fresh()->last_executed_at)->not->toBeNull()
        ->and($scheduledRestore->fresh()->last_skip_reason)->toBeNull();
    Queue::assertPushed(ProcessRestoreJob::class, 1);
});

test('a disabled scheduled restore runs and stays disabled', function () {
    $scheduledRestore = createScheduledRestore(['enabled' => false]);
    Snapshot::factory()->forServer($scheduledRestore->sourceServer)->create(['database_name' => 'app']);

    expect(RunScheduledRestoreAction::run($scheduledRestore)->restore)->not->toBeNull()
        ->and($scheduledRestore->fresh()->enabled)->toBeFalse();
});

test('it is skipped when no snapshot is eligible', function () {
    $scheduledRestore = createScheduledRestore();

    $result = RunScheduledRestoreAction::run($scheduledRestore);

    expect($result->skipReason)->toBe(ScheduledRestore::SKIP_NO_SNAPSHOT)
        ->and($scheduledRestore->fresh()->last_skip_reason)->toBe(ScheduledRestore::SKIP_NO_SNAPSHOT)
        ->and($scheduledRestore->fresh()->last_executed_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

test('it is skipped while its previous restore is in flight', function () {
    $scheduledRestore = createScheduledRestore();
    $snapshot = Snapshot::factory()->forServer($scheduledRestore->sourceServer)->create(['database_name' => 'app']);
    Restore::factory()->withStatus('running')->create([
        'snapshot_id' => $snapshot->id,
        'target_server_id' => $scheduledRestore->target_server_id,
        'scheduled_restore_id' => $scheduledRestore->id,
    ]);

    $result = RunScheduledRestoreAction::run($scheduledRestore);

    expect($result->skipReason)->toBe(ScheduledRestore::SKIP_PREVIOUS_IN_FLIGHT);
    Queue::assertNothingPushed();
});
