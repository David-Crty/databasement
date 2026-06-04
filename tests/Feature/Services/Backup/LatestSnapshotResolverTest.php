<?php

use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\LatestSnapshotResolver;

test('returns the most recent completed snapshot for the source server', function () {
    $source = DatabaseServer::factory()->create();
    $target = DatabaseServer::factory()->create();

    $older = Snapshot::factory()->forServer($source)->create(['database_name' => 'app']);
    $older->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

    $latest = Snapshot::factory()->forServer($source)->create(['database_name' => 'app']);
    $latest->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    $scheduled = createScheduledRestore(['source' => $source, 'target' => $target, 'source_database_name' => 'app']);

    $resolved = app(LatestSnapshotResolver::class)->resolve($scheduled);

    expect($resolved->id)->toBe($latest->id);
});

test('filters by source_database_name when specified', function () {
    $source = DatabaseServer::factory()->create();
    $target = DatabaseServer::factory()->create();

    $otherDb = Snapshot::factory()->forServer($source)->create(['database_name' => 'analytics']);
    $otherDb->forceFill(['created_at' => now()])->saveQuietly();

    $appDb = Snapshot::factory()->forServer($source)->create(['database_name' => 'app']);
    $appDb->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    $scheduled = createScheduledRestore(['source' => $source, 'target' => $target, 'source_database_name' => 'app']);

    $resolved = app(LatestSnapshotResolver::class)->resolve($scheduled);

    expect($resolved->id)->toBe($appDb->id);
});

test('ignores database name when source_database_name is null', function () {
    $source = DatabaseServer::factory()->create();
    $target = DatabaseServer::factory()->create();

    $first = Snapshot::factory()->forServer($source)->create(['database_name' => 'foo']);
    $first->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

    $second = Snapshot::factory()->forServer($source)->create(['database_name' => 'bar']);
    $second->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    $scheduled = createScheduledRestore(['source' => $source, 'target' => $target, 'source_database_name' => null]);

    $resolved = app(LatestSnapshotResolver::class)->resolve($scheduled);

    expect($resolved->id)->toBe($second->id);
});

test('skips snapshots whose job is not completed', function () {
    $source = DatabaseServer::factory()->create();
    $target = DatabaseServer::factory()->create();

    $running = Snapshot::factory()->forServer($source)->create(['database_name' => 'app']);
    $running->job->update(['status' => 'running']);

    $scheduled = createScheduledRestore(['source' => $source, 'target' => $target, 'source_database_name' => 'app']);

    expect(app(LatestSnapshotResolver::class)->resolve($scheduled))->toBeNull();
});

test('skips snapshots whose file is missing', function () {
    $source = DatabaseServer::factory()->create();
    $target = DatabaseServer::factory()->create();

    Snapshot::factory()->forServer($source)->fileMissing()->create(['database_name' => 'app']);

    $scheduled = createScheduledRestore(['source' => $source, 'target' => $target, 'source_database_name' => 'app']);

    expect(app(LatestSnapshotResolver::class)->resolve($scheduled))->toBeNull();
});

test('does not return snapshots from a different source server', function () {
    $source = DatabaseServer::factory()->create();
    $target = DatabaseServer::factory()->create();
    $other = DatabaseServer::factory()->create();

    Snapshot::factory()->forServer($other)->create(['database_name' => 'app']);

    $scheduled = createScheduledRestore(['source' => $source, 'target' => $target, 'source_database_name' => 'app']);

    expect(app(LatestSnapshotResolver::class)->resolve($scheduled))->toBeNull();
});
