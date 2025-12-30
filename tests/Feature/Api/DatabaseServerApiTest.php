<?php

use App\Models\DatabaseServer;
use App\Models\User;

test('unauthenticated users cannot access database servers api', function () {
    $response = $this->getJson('/api/v1/database-servers');

    $response->assertUnauthorized();
});

test('authenticated users can list database servers via api', function () {
    $user = User::factory()->create();
    DatabaseServer::factory()->count(3)->create();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/database-servers');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'host',
                    'port',
                    'database_type',
                    'database_names',
                    'backup_all_databases',
                    'description',
                    'created_at',
                    'updated_at',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('authenticated users can filter database servers by name', function () {
    $user = User::factory()->create();
    DatabaseServer::factory()->create(['name' => 'Production MySQL']);
    DatabaseServer::factory()->create(['name' => 'Staging PostgreSQL']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/database-servers?filter[name]=Production');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Production MySQL');
});

test('authenticated users can filter database servers by database type', function () {
    $user = User::factory()->create();
    DatabaseServer::factory()->create(['database_type' => 'mysql']);
    DatabaseServer::factory()->create(['database_type' => 'postgres']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/database-servers?filter[database_type]=mysql');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.database_type', 'mysql');
});

test('authenticated users can filter database servers by host', function () {
    $user = User::factory()->create();
    DatabaseServer::factory()->create(['host' => 'localhost']);
    DatabaseServer::factory()->create(['host' => 'db.example.com']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/database-servers?filter[host]=localhost');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.host', 'localhost');
});

test('authenticated users can sort database servers', function () {
    $user = User::factory()->create();
    DatabaseServer::factory()->create(['name' => 'Alpha Server']);
    DatabaseServer::factory()->create(['name' => 'Beta Server']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/database-servers?sort=name');

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha Server')
        ->assertJsonPath('data.1.name', 'Beta Server');
});

test('authenticated users can get a specific database server', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['name' => 'Test Server']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/database-servers/{$server->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $server->id)
        ->assertJsonPath('data.name', 'Test Server');
});

test('password is not exposed in database server api response', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['password' => 'secret-password']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/database-servers/{$server->id}");

    $response->assertOk()
        ->assertJsonMissing(['password' => 'secret-password']);
});
