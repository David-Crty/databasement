<?php

use App\Enums\UserRole;
use App\Jobs\MergeOrganizationJob;
use App\Models\Agent;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\Volume;
use App\Services\Organization\OrganizationMergeService;

test('job merges a fully populated source into a destination with overlapping members', function () {
    $source = Organization::factory()->create();
    $destination = Organization::factory()->create();

    // Source owns one of every resource type, plus a snapshot that should
    // follow its server. The destination already owns a server of its own.
    $sourceServer = DatabaseServer::factory()->create(['organization_id' => $source->id]);
    $sourceVolume = Volume::factory()->create(['organization_id' => $source->id]);
    $sourceAgent = Agent::factory()->create(['organization_id' => $source->id]);
    $sourceSshConfig = DatabaseServerSshConfig::factory()->create(['organization_id' => $source->id]);
    $snapshot = App\Models\Snapshot::factory()->forServer($sourceServer)->create();
    $snapshotBackupJobId = $snapshot->backup_job_id;

    $destinationServer = DatabaseServer::factory()->create(['organization_id' => $destination->id]);

    // Three membership situations:
    // - sourceOnly: must be carried over with its source role.
    // - destinationOnly: must be left untouched.
    // - conflicting: member of both with different roles -> destination role wins.
    $sourceOnly = User::factory()->create();
    $sourceOnly->organizations()->attach($source->id, ['role' => UserRole::Member]);

    $destinationOnly = User::factory()->create();
    $destinationOnly->organizations()->attach($destination->id, ['role' => UserRole::Member]);

    $conflicting = User::factory()->create();
    $conflicting->organizations()->attach($source->id, ['role' => UserRole::Admin]);
    $conflicting->organizations()->attach($destination->id, ['role' => UserRole::Viewer]);

    new MergeOrganizationJob($source->id, $destination->id, $sourceOnly->id)
        ->handle(app(OrganizationMergeService::class));

    $findServer = fn (string $id) => DatabaseServer::withoutGlobalScope(OrganizationScope::class)->find($id);

    // Every source resource now belongs to the destination; the destination's
    // own server is unchanged.
    expect($findServer($sourceServer->id)->organization_id)->toBe($destination->id)
        ->and(Volume::withoutGlobalScope(OrganizationScope::class)->find($sourceVolume->id)->organization_id)->toBe($destination->id)
        ->and(Agent::withoutGlobalScope(OrganizationScope::class)->find($sourceAgent->id)->organization_id)->toBe($destination->id)
        ->and(DatabaseServerSshConfig::withoutGlobalScope(OrganizationScope::class)->find($sourceSshConfig->id)->organization_id)->toBe($destination->id)
        ->and($findServer($destinationServer->id)->organization_id)->toBe($destination->id)
        ->and($snapshot->fresh()->database_server_id)->toBe($sourceServer->id)
        // The backup job has no organization_id; it follows the snapshot's
        // server through the merge and must survive intact.
        ->and($snapshot->fresh()->backup_job_id)->toBe($snapshotBackupJobId)
        ->and(BackupJob::find($snapshotBackupJobId))->not->toBeNull();

    // The snapshot followed its server (it has no organization_id of its own).

    // Members are unioned; the conflicting user keeps the destination role.
    $members = $destination->fresh()->users()->withPivot('role')->get();

    expect($members->pluck('id')->sort()->values()->all())
        ->toBe(collect([$sourceOnly->id, $destinationOnly->id, $conflicting->id])->sort()->values()->all())
        ->and($members->firstWhere('id', $sourceOnly->id)->pivot->role)->toBe(UserRole::Member->value)
        ->and($members->firstWhere('id', $destinationOnly->id)->pivot->role)->toBe(UserRole::Member->value)
        ->and($members->firstWhere('id', $conflicting->id)->pivot->role)->toBe(UserRole::Viewer->value)
        ->and(Organization::find($source->id))->toBeNull();
});

test('job is a no-op when an organization no longer exists', function () {
    $destination = Organization::factory()->create();

    (new MergeOrganizationJob('missing-source', $destination->id))->handle(app(OrganizationMergeService::class));

    expect(Organization::find($destination->id))->not->toBeNull();
});
