<?php

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;

/**
 * The scheduled entry point, end to end.
 *
 * Retention is only ever triggered in production through this command, which
 * dispatches a queued job that resolves the cleanup service. The service's own
 * behaviour is covered elsewhere; what these assert is that the wiring in
 * between still routes deletions through DeleteSnapshotAction, so a snapshot on
 * agent-backed storage cannot be dropped app-side by the scheduler.
 */
function expiredSnapshotFor(DatabaseServer $server): Snapshot
{
    $server->backups()->firstOrFail()->update(['retention_days' => 7]);

    $snapshot = Snapshot::factory()->forServer($server)->withFile()->create();
    $snapshot->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

    return $snapshot->fresh();
}

test('the scheduled command delegates an agent-backed snapshot instead of deleting it', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);
    $snapshot = expiredSnapshotFor($server);
    $path = $snapshot->files()->with('volume')->firstOrFail()->volume->config['path'].'/'.$snapshot->filename;

    $this->artisan('snapshots:cleanup')->assertSuccessful();

    // The record and the file both survive: only the agent may remove the file.
    expect(Snapshot::find($snapshot->id))->not->toBeNull()
        ->and(file_exists($path))->toBeTrue();

    $job = AgentJob::where('snapshot_id', $snapshot->id)
        ->where('type', AgentJob::TYPE_CLEANUP)
        ->sole();

    expect($job->status)->toBe(AgentJob::STATUS_PENDING)
        ->and($job->payload['targets'][0]['filename'])->toBe($snapshot->filename);
});

test('the scheduled command still deletes a snapshot with no agent outright', function () {
    $server = DatabaseServer::factory()->create();
    $snapshot = expiredSnapshotFor($server);
    $path = $snapshot->files()->with('volume')->firstOrFail()->volume->config['path'].'/'.$snapshot->filename;

    $this->artisan('snapshots:cleanup')->assertSuccessful();

    expect(Snapshot::find($snapshot->id))->toBeNull()
        ->and(file_exists($path))->toBeFalse()
        ->and(AgentJob::where('type', AgentJob::TYPE_CLEANUP)->exists())->toBeFalse();
});

test('the dry run reports without touching anything', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create(['agent_id' => $agent->id]);
    $snapshot = expiredSnapshotFor($server);

    $this->artisan('snapshots:cleanup --dry-run')->assertSuccessful();

    expect(Snapshot::find($snapshot->id))->not->toBeNull()
        ->and(AgentJob::where('type', AgentJob::TYPE_CLEANUP)->exists())->toBeFalse();
});
