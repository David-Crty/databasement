<?php

use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;

test('unauthenticated users cannot access snapshots api', function () {
    $this->getJson('/api/v1/snapshots')->assertUnauthorized();
});

test('authenticated users can list snapshots via api', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory->createSnapshots($server, 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/snapshots');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'database_name',
                    'database_type',
                    'method',
                    'filename',
                    'file_size',
                    'created_at',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('authenticated users can filter snapshots by database name', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['database_names' => ['production_db']]);
    $factory->createSnapshots($server1, 'manual');

    $server2 = DatabaseServer::factory()->create(['database_names' => ['staging_db']]);
    $factory->createSnapshots($server2, 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/snapshots?filter[database_name]=production');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.database_name', 'production_db');
});

test('authenticated users can filter snapshots by database type', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $mysqlServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'database_names' => ['mysql_db'],
    ]);
    $factory->createSnapshots($mysqlServer, 'manual');

    $pgServer = DatabaseServer::factory()->create([
        'database_type' => 'postgresql',
        'database_names' => ['pg_db'],
    ]);
    $factory->createSnapshots($pgServer, 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/snapshots?filter[database_type]=mysql');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.database_type', 'mysql');
});

test('authenticated users can get a specific snapshot', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshots = $factory->createSnapshots($server, 'manual');
    $snapshot = $snapshots[0];

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/snapshots/{$snapshot->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $snapshot->id)
        ->assertJsonPath('data.database_name', 'testdb');
});
