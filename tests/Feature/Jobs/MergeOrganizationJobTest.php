<?php

use App\Jobs\MergeOrganizationJob;
use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;

test('job merges the source organization into the destination', function () {
    $source = Organization::factory()->create();
    $destination = Organization::factory()->create();
    $server = DatabaseServer::factory()->create(['organization_id' => $source->id]);

    (new MergeOrganizationJob($source->id, $destination->id))->handle();

    expect(DatabaseServer::withoutGlobalScope(OrganizationScope::class)->find($server->id)->organization_id)->toBe($destination->id)
        ->and(Organization::find($source->id))->toBeNull();
});

test('job is a no-op when an organization no longer exists', function () {
    $destination = Organization::factory()->create();

    (new MergeOrganizationJob('missing-source', $destination->id))->handle();

    expect(Organization::find($destination->id))->not->toBeNull();
});
