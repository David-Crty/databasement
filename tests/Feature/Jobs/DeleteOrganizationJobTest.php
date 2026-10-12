<?php

use App\Actions\Organization\DeleteOrganizationAction;
use App\Jobs\DeleteOrganizationJob;
use App\Models\Organization;

test('job deletes the organization', function () {
    $org = Organization::factory()->create();

    (new DeleteOrganizationJob($org->id))->handle();

    expect(Organization::find($org->id))->toBeNull();
});

test('job passes keepFiles to the deletion', function () {
    $org = Organization::factory()->create();

    DeleteOrganizationAction::shouldRun()
        ->once()
        ->withArgs(fn (Organization $organization, ?int $triggeredByUserId, bool $keepFiles) => $organization->is($org) && $keepFiles);

    (new DeleteOrganizationJob($org->id, null, keepFiles: true))->handle();
});

test('job is a no-op when the organization no longer exists', function () {
    DeleteOrganizationAction::shouldRun()->never();

    (new DeleteOrganizationJob('missing-organization'))->handle();
});
