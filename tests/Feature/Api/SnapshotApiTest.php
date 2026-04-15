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
    $factory->createSnapshots($server->backups->first(), 'manual');

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
    $factory->createSnapshots($server1->backups->first(), 'manual');

    $server2 = DatabaseServer::factory()->create(['database_names' => ['staging_db']]);
    $factory->createSnapshots($server2->backups->first(), 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/snapshots?filter[database_name]=production');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.database_name', 'production_db');
});

test('authenticated users can filter snapshots by database server id', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['database_names' => ['db_one']]);
    $factory->createSnapshots($server1->backups->first(), 'manual');

    $server2 = DatabaseServer::factory()->create(['database_names' => ['db_two']]);
    $factory->createSnapshots($server2->backups->first(), 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/snapshots?filter[database_server_id]={$server1->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.server.id', $server1->id);
});

test('authenticated users can filter snapshots by database type', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $mysqlServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'database_names' => ['mysql_db'],
    ]);
    $factory->createSnapshots($mysqlServer->backups->first(), 'manual');

    $pgServer = DatabaseServer::factory()->create([
        'database_type' => 'postgres',
        'database_names' => ['pg_db'],
    ]);
    $factory->createSnapshots($pgServer->backups->first(), 'manual');

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
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual');
    $snapshot = $snapshots[0];

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/snapshots/{$snapshot->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $snapshot->id)
        ->assertJsonPath('data.database_name', 'testdb');
});
