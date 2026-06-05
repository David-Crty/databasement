<?php

use App\Jobs\ProcessRestoreJob;
use App\Models\BackupJob;
use App\Models\Restore;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Queue;

test('fails when scheduled restore ID does not exist', function () {
    $this->artisan('restores:run', ['scheduledRestore' => 'missing-id'])
        ->expectsOutput('Scheduled restore not found: missing-id')
        ->assertExitCode(1);
});

test('skips disabled scheduled restore', function () {
    Queue::fake();

    $scheduled = createScheduledRestore(['enabled' => false]);

    $this->artisan('restores:run', ['scheduledRestore' => $scheduled->id])
        ->expectsOutputToContain('disabled')
        ->assertExitCode(0);

    Queue::assertNothingPushed();

    $scheduled->refresh();
    expect($scheduled->last_skip_reason)->toBe(ScheduledRestore::SKIP_DISABLED)
        ->and($scheduled->last_executed_at)->not->toBeNull();
});

test('skips when no eligible snapshot exists', function () {
    Queue::fake();

    $scheduled = createScheduledRestore();

    $this->artisan('restores:run', ['scheduledRestore' => $scheduled->id])
        ->expectsOutputToContain('No eligible snapshot')
        ->assertExitCode(0);

    Queue::assertNothingPushed();

    $scheduled->refresh();
    expect($scheduled->last_skip_reason)->toBe(ScheduledRestore::SKIP_NO_SNAPSHOT);
});

test('skips when a previous restore is still in flight', function () {
    Queue::fake();

    $scheduled = createScheduledRestore();

    Snapshot::factory()->forServer($scheduled->sourceServer)->create(['database_name' => 'app']);

    $job = BackupJob::create(['status' => 'running']);
    Restore::create([
        'backup_job_id' => $job->id,
        'snapshot_id' => Snapshot::first()->id,
        'target_server_id' => $scheduled->target_server_id,
        'schema_name' => 'restored_db',
        'scheduled_restore_id' => $scheduled->id,
    ]);

    $this->artisan('restores:run', ['scheduledRestore' => $scheduled->id])
        ->expectsOutputToContain('previous restore still in flight')
        ->assertExitCode(0);

    Queue::assertNothingPushed();

    $scheduled->refresh();
    expect($scheduled->last_skip_reason)->toBe(ScheduledRestore::SKIP_PREVIOUS_IN_FLIGHT);
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

    $scheduled->refresh();
    expect($scheduled->lastRestore?->id)->toBe($restore->id)
        ->and($scheduled->last_executed_at)->not->toBeNull()
        ->and($scheduled->last_skip_reason)->toBeNull();
});

test('passes options to the created restore', function () {
    Queue::fake();

    $scheduled = createScheduledRestore([
        'options' => ['force_database' => true, 'owner_user' => 'webapp'],
    ]);

    Snapshot::factory()->forServer($scheduled->sourceServer)->create(['database_name' => 'app']);

    $this->artisan('restores:run', ['scheduledRestore' => $scheduled->id])
        ->assertExitCode(0);

    $restore = Restore::where('scheduled_restore_id', $scheduled->id)->firstOrFail();
    expect($restore->getOption('force_database'))->toBeTrue()
        ->and($restore->getOption('owner_user'))->toBe('webapp');
});

test('completed previous restores do not block new ones', function () {
    Queue::fake();

    $scheduled = createScheduledRestore();

    Snapshot::factory()->forServer($scheduled->sourceServer)->create(['database_name' => 'app']);

    $completedJob = BackupJob::create(['status' => 'completed']);
    Restore::create([
        'backup_job_id' => $completedJob->id,
        'snapshot_id' => Snapshot::first()->id,
        'target_server_id' => $scheduled->target_server_id,
        'schema_name' => 'restored_db',
        'scheduled_restore_id' => $scheduled->id,
    ]);

    $this->artisan('restores:run', ['scheduledRestore' => $scheduled->id])
        ->assertExitCode(0);

    Queue::assertPushed(ProcessRestoreJob::class, 1);
});
