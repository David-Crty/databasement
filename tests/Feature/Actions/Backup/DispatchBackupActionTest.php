<?php

use App\Actions\Backup\DispatchBackupAction;
use App\Enums\AgentJobType;
use App\Jobs\ProcessBackupJob;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

test('a backup of a server the app reaches is queued', function () {
    $snapshot = pendingSnapshot(DatabaseServer::factory()->create(['database_names' => ['app']]));

    DispatchBackupAction::run($snapshot);

    Queue::assertPushed(ProcessBackupJob::class, fn (ProcessBackupJob $job) => $job->snapshotId === $snapshot->id);
    expect(AgentJob::count())->toBe(0);
});

test('a backup of an agent-backed server is handed to its agent', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['app'], 'agent_id' => Agent::factory()->create()->id]);
    $snapshot = pendingSnapshot($server);

    DispatchBackupAction::run($snapshot);

    Queue::assertNothingPushed();
    $agentJob = AgentJob::where('snapshot_id', $snapshot->id)->sole();
    expect($agentJob->type)->toBe(AgentJobType::Backup)
        ->and($agentJob->database_server_id)->toBe($server->id)
        ->and($agentJob->payload['database']['database_name'])->toBe('app');
});
