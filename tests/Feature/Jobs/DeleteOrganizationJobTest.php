<?php

use App\Jobs\DeleteOrganizationJob;
use App\Models\Organization;
use App\Services\Organization\OrganizationMergeService;

test('job deletes an empty organization', function () {
    $org = Organization::factory()->create();

    (new DeleteOrganizationJob($org->id))->handle(app(OrganizationMergeService::class));

    expect(Organization::find($org->id))->toBeNull();
});

test('job leaves an organization with resources intact', function () {
    $org = Organization::factory()->create();
    App\Models\DatabaseServer::factory()->create(['organization_id' => $org->id]);

    try {
        (new DeleteOrganizationJob($org->id))->handle(app(OrganizationMergeService::class));
    } catch (InvalidArgumentException) {
        // expected
    }

    expect(Organization::find($org->id))->not->toBeNull();
});
