<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationMergeService;
use App\Services\Roles\SyncUserAbilitiesAction;

// The merge/delete happy paths and member union are exercised end-to-end through
// MergeOrganizationJobTest and DeleteOrganizationJobTest. These tests cover only
// the service's own guard clauses, which nothing else triggers.

test('merge carries a source member\'s direct abilities into the destination', function () {
    $source = Organization::factory()->create();
    $destination = Organization::factory()->create();

    // A viewer in the source with an extra ability granted directly on top of
    // their role — the grant must follow them into the destination.
    $user = User::factory()->create();
    attachUserToOrg($user, $source, 'viewer');
    app(SyncUserAbilitiesAction::class)->execute($user, ['run-backups'], $source);

    app(OrganizationMergeService::class)->merge($source, $destination);

    expect($user->fresh()->directAbilitiesIn($destination))->toContain('run-backups');
});

test('merge rejects the default organization as source', function () {
    app(OrganizationMergeService::class)->merge(Organization::default(), Organization::factory()->create());
})->throws(InvalidArgumentException::class);

test('merge rejects merging an organization into itself', function () {
    $org = Organization::factory()->create();

    app(OrganizationMergeService::class)->merge($org, $org);
})->throws(InvalidArgumentException::class);

test('delete rejects the default organization', function () {
    app(OrganizationMergeService::class)->delete(Organization::default());
})->throws(InvalidArgumentException::class);
