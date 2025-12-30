<?php

use App\Models\Volume;
use App\Queries\VolumeQuery;

test('can search volumes by name', function () {
    Volume::factory()->create(['name' => 'Production Backups']);
    Volume::factory()->create(['name' => 'Development Storage']);

    $results = VolumeQuery::buildFromParams(search: 'Production')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Production Backups');
});

test('can search volumes by type', function () {
    Volume::factory()->create(['name' => 'Local Volume', 'type' => 'local']);
    Volume::factory()->create(['name' => 'S3 Volume', 'type' => 's3']);

    $results = VolumeQuery::buildFromParams(search: 's3')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('S3 Volume');
});

test('can sort volumes by column', function () {
    Volume::factory()->create(['name' => 'Zebra Volume']);
    Volume::factory()->create(['name' => 'Alpha Volume']);

    $results = VolumeQuery::buildFromParams(
        sortColumn: 'name',
        sortDirection: 'asc'
    )->get();

    expect($results->first()->name)->toBe('Alpha Volume')
        ->and($results->last()->name)->toBe('Zebra Volume');
});
