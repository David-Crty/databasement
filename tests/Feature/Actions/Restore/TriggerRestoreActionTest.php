<?php

use App\Actions\Restore\TriggerRestoreAction;
use App\Enums\AgentJobType;
use App\Jobs\ProcessRestoreJob;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\DTO\RestoreOptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Queue::fake());

test('it creates a restore and queues it for a server the app reaches', function () {
    [$source, $target] = createRestoreServerPair();
    $snapshot = Snapshot::factory()->forServer($source)->create();

    $restore = TriggerRestoreAction::run($snapshot, $target, 'restored_db', triggeredByUserId: null);

    expect($restore->target_server_id)->toBe($target->id)
        ->and($restore->snapshot_id)->toBe($snapshot->id)
        ->and($restore->schema_name)->toBe('restored_db');
    Queue::assertPushed(ProcessRestoreJob::class, fn (ProcessRestoreJob $job) => $job->restoreId === $restore->id);
});

test('it hands the restore to the agent of an agent-backed server', function () {
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql', 'agent_id' => Agent::factory()->create()->id]);
    $snapshot = Snapshot::factory()->forServer(DatabaseServer::factory()->create(['database_type' => 'mysql']))
        ->onVolumes(Volume::factory()->s3()->create())
        ->create();

    $restore = TriggerRestoreAction::run($snapshot, $target, 'restored_db');

    Queue::assertNothingPushed();
    expect(AgentJob::where('restore_id', $restore->id)->sole()->type)->toBe(AgentJobType::Restore);
});

test('it stores the options without their empty values', function () {
    [$source, $target] = createRestoreServerPair('postgres');
    $snapshot = Snapshot::factory()->forServer($source)->create();

    $restore = TriggerRestoreAction::run($snapshot, $target, 'restored_db', new RestoreOptions(ownerUser: ' pg_owner ', parallelRestore: true));

    expect($restore->options)->toBe(['owner_user' => 'pg_owner', 'parallel_restore' => true]);
});

test('it restores from the chosen copy', function () {
    [$source, $target] = createRestoreServerPair();
    $snapshot = Snapshot::factory()->forServer($source)
        ->onVolumes(Volume::factory()->create(), $second = Volume::factory()->create())
        ->create();
    $copy = $snapshot->files()->where('volume_id', $second->id)->sole();

    $restore = TriggerRestoreAction::run($snapshot, $target, 'restored_db', snapshotFileId: $copy->id);

    expect($restore->snapshot_file_id)->toBe($copy->id);
});

test('it records the scheduled restore it runs for', function () {
    $scheduledRestore = createScheduledRestore();
    $snapshot = Snapshot::factory()->forServer($scheduledRestore->sourceServer)->create();

    $restore = TriggerRestoreAction::run($snapshot, $scheduledRestore->targetServer, 'restored_db', scheduledRestoreId: $scheduledRestore->id);

    expect($restore->scheduled_restore_id)->toBe($scheduledRestore->id);
});

test('a snapshot that did not complete cannot be restored', function () {
    [$source, $target] = createRestoreServerPair();
    $snapshot = Snapshot::factory()->forServer($source)->failed()->create();

    expect(fn () => TriggerRestoreAction::run($snapshot, $target, 'restored_db'))
        ->toThrow(ValidationException::class, 'Snapshot is not completed');

    Queue::assertNothingPushed();
});

test('an agent-backed target is rejected when the snapshot only exists on a local volume', function () {
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql', 'agent_id' => Agent::factory()->create()->id]);
    $snapshot = Snapshot::factory()->forServer(DatabaseServer::factory()->create(['database_type' => 'mysql']))
        ->onVolumes(Volume::factory()->local()->create())
        ->create();

    expect(fn () => TriggerRestoreAction::run($snapshot, $target, 'restored_db'))
        ->toThrow(ValidationException::class, 'cannot read snapshots stored on a local volume');

    expect(AgentJob::count())->toBe(0);
});
