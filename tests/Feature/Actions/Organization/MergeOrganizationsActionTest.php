<?php

use App\Actions\Organization\MergeOrganizationsAction;
use App\Actions\Role\SyncUserAbilitiesAction;
use App\Models\Agent;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;

test('merge moves every resource and unions members with overlapping roles', function () {
    $source = Organization::factory()->create();
    $destination = Organization::factory()->create();

    // Source owns one of every resource type, plus a snapshot that should
    // follow its server. The destination already owns a server of its own.
    $sourceServer = DatabaseServer::factory()->create(['organization_id' => $source->id]);
    $sourceVolume = Volume::factory()->create(['organization_id' => $source->id]);
    $sourceAgent = Agent::factory()->create(['organization_id' => $source->id]);
    $sourceSshConfig = DatabaseServerSshConfig::factory()->create(['organization_id' => $source->id]);
    $snapshot = Snapshot::factory()->forServer($sourceServer)->create();
    $snapshotBackupJobId = $snapshot->backup_job_id;

    $destinationServer = DatabaseServer::factory()->create(['organization_id' => $destination->id]);

    // Three membership situations:
    // - sourceOnly: must be carried over with its source role.
    // - destinationOnly: must be left untouched.
    // - conflicting: member of both with different roles -> destination role wins.
    $sourceOnly = User::factory()->create();
    attachUserToOrg($sourceOnly, $source, 'member');

    $destinationOnly = User::factory()->create();
    attachUserToOrg($destinationOnly, $destination, 'member');

    $conflicting = User::factory()->create();
    attachUserToOrg($conflicting, $source, 'admin');
    attachUserToOrg($conflicting, $destination, 'viewer');

    MergeOrganizationsAction::run($source, $destination);

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
    $members = $destination->fresh()->users()->get();

    expect($members->pluck('id')->sort()->values()->all())
        ->toBe(collect([$sourceOnly->id, $destinationOnly->id, $conflicting->id])->sort()->values()->all())
        ->and($sourceOnly->fresh()->roleNameIn($destination))->toBe('member')
        ->and($destinationOnly->fresh()->roleNameIn($destination))->toBe('member')
        ->and($conflicting->fresh()->roleNameIn($destination))->toBe('viewer')
        ->and(Organization::find($source->id))->toBeNull();
});

test('merge carries a source member\'s direct abilities into the destination', function () {
    $source = Organization::factory()->create();
    $destination = Organization::factory()->create();

    // A viewer in the source with an extra ability granted directly on top of
    // their role — the grant must follow them into the destination.
    $user = User::factory()->create();
    attachUserToOrg($user, $source, 'viewer');
    SyncUserAbilitiesAction::run($user, ['run-backups'], $source);

    MergeOrganizationsAction::run($source, $destination);

    expect($user->fresh()->directAbilitiesIn($destination))->toContain('run-backups');
});

test('merge rejects the default organization as source', function () {
    MergeOrganizationsAction::run(Organization::default(), Organization::factory()->create());
})->throws(InvalidArgumentException::class);

test('merge rejects merging an organization into itself', function () {
    $org = Organization::factory()->create();

    MergeOrganizationsAction::run($org, $org);
})->throws(InvalidArgumentException::class);
