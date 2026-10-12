<?php

use App\Actions\Agent\FailAgentJobAction;
use App\Actions\Backup\DispatchBackupAction;
use App\Actions\Snapshot\DeleteSnapshotAction;
use App\Enums\BackupJobStatus;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;

function pendingAgentBackupJob(): AgentJob
{
    $server = DatabaseServer::factory()->create([
        'agent_id' => Agent::factory()->create()->id,
        'database_names' => ['app'],
        'database_selection_mode' => 'selected',
    ]);
    $snapshot = pendingSnapshot($server);
    DispatchBackupAction::run($snapshot);

    return AgentJob::where('snapshot_id', $snapshot->id)->sole();
}

test('it fails the job and the backup it was running', function () {
    $agentJob = pendingAgentBackupJob();

    expect(FailAgentJobAction::run($agentJob, 'Disk full'))->toBeTrue();

    expect($agentJob->fresh())->status->toBe(AgentJob::STATUS_FAILED)->error_message->toBe('Disk full')
        ->and($agentJob->snapshot->job->fresh())->status->toBe(BackupJobStatus::Failed)->error_message->toBe('Disk full');
});

test('the logs the agent reported are kept on the job record', function () {
    $agentJob = pendingAgentBackupJob();
    $entry = ['timestamp' => now()->toIso8601String(), 'type' => 'log', 'level' => 'error', 'message' => 'mysqldump: access denied'];

    FailAgentJobAction::run($agentJob, 'Dump failed', logs: [$entry]);

    expect(collect($agentJob->snapshot->job->fresh()->logs)->pluck('message'))->toContain('mysqldump: access denied');
});

test('a failed cleanup job takes the snapshot out of the deleting state', function () {
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andThrow(new RuntimeException('Volume unreachable'));
    app()->instance(FilesystemProvider::class, $provider);
    $server = DatabaseServer::factory()->create(['agent_id' => Agent::factory()->create()->id]);
    $snapshot = Snapshot::factory()->forServer($server)->onVolumes(Volume::factory()->s3()->create())->withFile()->create();
    DeleteSnapshotAction::run($snapshot);

    FailAgentJobAction::run(AgentJob::where('snapshot_id', $snapshot->id)->sole(), 'The agent crashed');

    expect($snapshot->fresh()->deleting)->toBeFalse()
        ->and($snapshot->files()->sole()->error)->toBe('The agent crashed');
});

test('only if pending, a job an agent claimed meanwhile is left alone', function () {
    $agentJob = pendingAgentBackupJob();
    $agentJob->claim(Agent::factory()->create());

    expect(FailAgentJobAction::run($agentJob, 'Nobody picked it up', onlyIfPending: true))->toBeFalse();

    expect($agentJob->fresh()->status)->toBe(AgentJob::STATUS_CLAIMED)
        ->and($agentJob->snapshot->job->fresh()->status)->not->toBe(BackupJobStatus::Failed);
});
