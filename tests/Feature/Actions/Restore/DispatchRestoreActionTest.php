<?php

use App\Actions\Restore\DispatchRestoreAction;
use App\Enums\AgentJobType;
use App\Enums\BackupJobStatus;
use App\Jobs\ProcessRestoreJob;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Notifications\RestoreFailedNotification;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DTO\RestoreConfig;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

test('a restore onto a server the app reaches is queued', function () {
    [$source, $target] = createRestoreServerPair();
    $restore = app(BackupJobFactory::class)->createRestore(Snapshot::factory()->forServer($source)->create(), $target, 'restored_db');

    DispatchRestoreAction::run($restore);

    Queue::assertPushed(ProcessRestoreJob::class, fn (ProcessRestoreJob $job) => $job->restoreId === $restore->id);
    expect(AgentJob::count())->toBe(0);
});

test('an agent-backed target gets a single-attempt agent job instead of a queued job', function () {
    ['restore' => $restore, 'agentJob' => $agentJob] = dispatchAgentRestore(Agent::factory()->create());

    Queue::assertNothingPushed();
    expect($agentJob->type)->toBe(AgentJobType::Restore)
        ->and($agentJob->database_server_id)->toBe($restore->target_server_id)
        ->and($agentJob->max_attempts)->toBe(1);

    $config = RestoreConfig::fromPayload($agentJob->payload, '/tmp/work');
    expect($config->schemaName)->toBe('restored_db')
        ->and($config->snapshotFilename)->toBe($restore->snapshot->filename)
        ->and($config->snapshotDatabaseName)->toBe($restore->snapshot->database_name)
        ->and($config->targetServer->serverId)->toBe($restore->target_server_id)
        ->and($config->snapshotVolume->type)->toBe('s3');
});

test('the parallel restore option reaches the agent', function () {
    $target = DatabaseServer::factory()->create(['database_type' => 'postgres', 'agent_id' => Agent::factory()->create()->id]);
    $snapshot = Snapshot::factory()->forServer(DatabaseServer::factory()->create(['database_type' => 'postgres']))
        ->onVolumes(Volume::factory()->s3()->create())
        ->create();
    $restore = app(BackupJobFactory::class)->createRestore($snapshot, $target, 'restored_db', options: ['parallel_restore' => true]);

    DispatchRestoreAction::run($restore);

    expect(RestoreConfig::fromPayload(AgentJob::where('restore_id', $restore->id)->sole()->payload, '/tmp/work')->parallelRestore)->toBeTrue();
});

test('the agent reads the copy it can reach, not the local one', function () {
    $s3 = Volume::factory()->s3()->create();

    ['agentJob' => $agentJob] = dispatchAgentRestore(Agent::factory()->create(), Volume::factory()->local()->create(), $s3);

    expect($agentJob->payload['volume']['id'])->toBe($s3->id);
});

test('a restore whose copy vanishes before dispatch is failed and notified instead of left pending', function () {
    NotificationChannel::factory()->email()->create();
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql', 'agent_id' => Agent::factory()->create()->id]);
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->onVolumes(Volume::factory()->s3()->create())->create();
    $restore = app(BackupJobFactory::class)->createRestore($snapshot, $target, 'restored_db');

    $snapshot->files()->update(['file_exists' => false]);

    expect(fn () => DispatchRestoreAction::run($restore))
        ->toThrow(RuntimeException::class, 'No copy of this snapshot is available');

    expect($restore->job->fresh()->status)->toBe(BackupJobStatus::Failed)
        ->and(AgentJob::where('restore_id', $restore->id)->exists())->toBeFalse();
    Notification::assertSentTimes(RestoreFailedNotification::class, 1);
});
