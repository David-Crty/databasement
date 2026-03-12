<?php

use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('unauthenticated users cannot trigger a restore', function () {
    $server = DatabaseServer::factory()->create();

    $response = $this->postJson("/api/v1/database-servers/{$server->id}/restore");

    $response->assertUnauthorized();
});

test('viewer users cannot trigger a restore', function () {
    $user = User::factory()->create(['role' => User::ROLE_VIEWER]);
    $server = DatabaseServer::factory()->create();
    $snapshot = Snapshot::factory()->forServer($server)->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/restore", [
            'snapshot_id' => $snapshot->id,
            'schema_name' => 'testdb',
        ]);

    $response->assertForbidden();
});

test('authenticated users can trigger a restore', function () {
    Queue::fake();

    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($server)->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/restore", [
            'snapshot_id' => $snapshot->id,
            'schema_name' => 'target_db',
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('message', 'Restore started successfully!')
        ->assertJsonStructure([
            'message',
            'restore' => [
                'id',
                'schema_name',
                'created_at',
                'updated_at',
                'snapshot',
                'target_server',
                'job',
            ],
        ]);

    Queue::assertPushed(ProcessRestoreJob::class);
});

test('restore requires snapshot_id and schema_name', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/restore", []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['snapshot_id', 'schema_name']);
});

test('restore rejects incompatible snapshot database type', function () {
    Queue::fake();

    $user = User::factory()->create();
    $mysqlServer = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $postgresServer = DatabaseServer::factory()->create(['database_type' => 'postgres']);
    $snapshot = Snapshot::factory()->forServer($postgresServer)->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$mysqlServer->id}/restore", [
            'snapshot_id' => $snapshot->id,
            'schema_name' => 'target_db',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Snapshot database type does not match the target server.');
});

test('restore validates schema name format for non-sqlite servers', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($server)->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/restore", [
            'snapshot_id' => $snapshot->id,
            'schema_name' => 'invalid-name!',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['schema_name']);
});

test('restore rejects nonexistent snapshot', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/restore", [
            'snapshot_id' => 'nonexistent-id',
            'schema_name' => 'testdb',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['snapshot_id']);
});
