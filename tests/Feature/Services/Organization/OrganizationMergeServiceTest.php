<?php

use App\Models\Organization;
use App\Services\Organization\OrganizationMergeService;

// The merge/delete happy paths and member union are exercised end-to-end through
// MergeOrganizationJobTest and DeleteOrganizationJobTest. These tests cover only
// the service's own guard clauses, which nothing else triggers.

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
