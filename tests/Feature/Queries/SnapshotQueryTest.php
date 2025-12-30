<?php

use App\Models\DatabaseServer;
use App\Queries\SnapshotQuery;
use App\Services\Backup\BackupJobFactory;

test('can search snapshots by database name', function () {
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['production_db']]);
    $snapshots = $factory->createSnapshots($server, 'manual');
    $snapshots[0]->update(['database_name' => 'production_db']);

    $server2 = DatabaseServer::factory()->create(['database_names' => ['staging_db']]);
    $snapshots2 = $factory->createSnapshots($server2, 'manual');
    $snapshots2[0]->update(['database_name' => 'staging_db']);

    $results = SnapshotQuery::buildFromParams(search: 'production')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->database_name)->toBe('production_db');
});

test('can search snapshots by server name', function () {
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'MySQL Production', 'database_names' => ['db1']]);
    $factory->createSnapshots($server, 'manual');

    $server2 = DatabaseServer::factory()->create(['name' => 'PostgreSQL Dev', 'database_names' => ['db2']]);
    $factory->createSnapshots($server2, 'manual');

    $results = SnapshotQuery::buildFromParams(search: 'MySQL')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->databaseServer->name)->toBe('MySQL Production');
});

test('can filter snapshots by status', function () {
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    $completedSnapshots = $factory->createSnapshots($server, 'manual');
    $completedSnapshots[0]->job->update(['status' => 'completed']);

    $failedSnapshots = $factory->createSnapshots($server, 'manual');
    $failedSnapshots[0]->job->update(['status' => 'failed']);

    $results = SnapshotQuery::buildFromParams(statusFilter: 'completed')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->job->status)->toBe('completed');
});

test('can sort snapshots by column', function () {
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    $snapshot1 = $factory->createSnapshots($server, 'manual')[0];
    $snapshot1->update(['file_size' => 1000]);

    $snapshot2 = $factory->createSnapshots($server, 'manual')[0];
    $snapshot2->update(['file_size' => 5000]);

    $results = SnapshotQuery::buildFromParams(
        sortColumn: 'file_size',
        sortDirection: 'desc'
    )->get();

    expect($results->first()->file_size)->toBe(5000)
        ->and($results->last()->file_size)->toBe(1000);
});
