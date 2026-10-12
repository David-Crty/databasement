<?php

use App\Actions\ScheduledRestore\RunScheduledRestoreAction;
use App\Jobs\ProcessRestoreJob;
use App\Models\Restore;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use App\Services\Backup\DTO\ScheduledRestoreResult;
use Illuminate\Support\Facades\Queue;

test('fails when scheduled restore ID does not exist', function () {
    $this->artisan('restores:run', ['scheduledRestore' => 'missing-id'])
        ->expectsOutput('Scheduled restore not found: missing-id')
        ->assertExitCode(1);
});

test('creates restore and dispatches job for happy path', function () {
    Queue::fake();

    $scheduled = createScheduledRestore();

    $snapshot = Snapshot::factory()->forServer($scheduled->sourceServer)->create(['database_name' => 'app']);

    $this->artisan('restores:run', ['scheduledRestore' => $scheduled->id])
        ->expectsOutputToContain('Dispatched restore')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessRestoreJob::class, 1);

    $restore = Restore::where('scheduled_restore_id', $scheduled->id)->firstOrFail();
    expect($restore->snapshot_id)->toBe($snapshot->id)
        ->and($restore->target_server_id)->toBe($scheduled->target_server_id)
        ->and($restore->schema_name)->toBe('restored_db')
        ->and($restore->triggered_by_user_id)->toBeNull();
});

test('reports why a run was skipped', function (string $reason, string $output) {
    $scheduled = createScheduledRestore();
    RunScheduledRestoreAction::shouldRun()->once()->andReturn(ScheduledRestoreResult::skipped($reason));

    $this->artisan('restores:run', ['scheduledRestore' => $scheduled->id])
        ->expectsOutputToContain($output)
        ->assertExitCode(0);
})->with([
    'no snapshot' => [ScheduledRestore::SKIP_NO_SNAPSHOT, 'No eligible snapshot'],
    'previous restore in flight' => [ScheduledRestore::SKIP_PREVIOUS_IN_FLIGHT, 'previous restore still in flight'],
]);
