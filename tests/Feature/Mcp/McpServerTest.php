<?php

use App\Jobs\ProcessRestoreJob;
use App\Mcp\Servers\DatabasementServer;
use App\Mcp\Tools\GetJobStatusTool;
use App\Mcp\Tools\ListDatabaseServersTool;
use App\Mcp\Tools\ListSnapshotsTool;
use App\Mcp\Tools\TriggerBackupTool;
use App\Mcp\Tools\TriggerRestoreTool;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('list database servers returns server data', function () {
    $user = User::factory()->create();
    $server = createDatabaseServer(['name' => 'My MySQL']);

    $response = DatabasementServer::actingAs($user)->tool(ListDatabaseServersTool::class);

    $response->assertOk()
        ->assertSee('My MySQL')
        ->assertSee($server->id);
});

test('list database servers filters by type', function () {
    $user = User::factory()->create();
    createDatabaseServer(['name' => 'MySQL Server', 'database_type' => 'mysql']);
    createDatabaseServer(['name' => 'Postgres Server', 'database_type' => 'postgres']);

    $response = DatabasementServer::actingAs($user)->tool(ListDatabaseServersTool::class, [
        'database_type' => 'mysql',
    ]);

    $response->assertOk()
        ->assertSee('MySQL Server')
        ->assertDontSee('Postgres Server');
});

test('list database servers returns empty message when none exist', function () {
    $user = User::factory()->create();

    $response = DatabasementServer::actingAs($user)->tool(ListDatabaseServersTool::class);

    $response->assertOk()
        ->assertSee('No database servers found');
});

test('list snapshots returns snapshot data', function () {
    $user = User::factory()->create();
    $server = createDatabaseServer(['name' => 'Test Server']);
    $snapshot = Snapshot::factory()->forServer($server)->create([
        'database_name' => 'mydb',
    ]);

    $response = DatabasementServer::actingAs($user)->tool(ListSnapshotsTool::class);

    $response->assertOk()
        ->assertSee('mydb')
        ->assertSee($snapshot->id);
});

test('list snapshots filters by server', function () {
    $user = User::factory()->create();
    $server1 = createDatabaseServer(['name' => 'Server 1']);
    $server2 = createDatabaseServer(['name' => 'Server 2']);
    Snapshot::factory()->forServer($server1)->create(['database_name' => 'db_one']);
    Snapshot::factory()->forServer($server2)->create(['database_name' => 'db_two']);

    $response = DatabasementServer::actingAs($user)->tool(ListSnapshotsTool::class, [
        'database_server_id' => $server1->id,
    ]);

    $response->assertOk()
        ->assertSee('db_one')
        ->assertDontSee('db_two');
});

test('trigger backup dispatches job', function () {
    Queue::fake();
    $user = User::factory()->create();
    $server = createDatabaseServer(['database_names' => ['testdb']]);

    $response = DatabasementServer::actingAs($user)->tool(TriggerBackupTool::class, [
        'database_server_id' => $server->id,
    ]);

    $response->assertOk()
        ->assertSee('Backup started successfully');
});

test('trigger backup rejects viewer users', function () {
    $user = User::factory()->create(['role' => User::ROLE_VIEWER]);
    $server = createDatabaseServer();

    $response = DatabasementServer::actingAs($user)->tool(TriggerBackupTool::class, [
        'database_server_id' => $server->id,
    ]);

    $response->assertHasErrors();
});

test('trigger restore dispatches job', function () {
    Queue::fake();
    $user = User::factory()->create();
    $server = createDatabaseServer(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($server)->create();

    $response = DatabasementServer::actingAs($user)->tool(TriggerRestoreTool::class, [
        'snapshot_id' => $snapshot->id,
        'database_server_id' => $server->id,
        'schema_name' => 'restore_target',
    ]);

    $response->assertOk()
        ->assertSee('Restore started successfully');

    Queue::assertPushed(ProcessRestoreJob::class);
});

test('trigger restore rejects type mismatch', function () {
    Queue::fake();
    $user = User::factory()->create();
    $mysqlServer = createDatabaseServer(['database_type' => 'mysql']);
    $pgServer = createDatabaseServer(['database_type' => 'postgres']);
    $snapshot = Snapshot::factory()->forServer($mysqlServer)->create();

    $response = DatabasementServer::actingAs($user)->tool(TriggerRestoreTool::class, [
        'snapshot_id' => $snapshot->id,
        'database_server_id' => $pgServer->id,
        'schema_name' => 'restore_target',
    ]);

    $response->assertHasErrors();
    Queue::assertNothingPushed();
});

test('trigger restore rejects viewer users', function () {
    $user = User::factory()->create(['role' => User::ROLE_VIEWER]);
    $server = createDatabaseServer(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($server)->create();

    $response = DatabasementServer::actingAs($user)->tool(TriggerRestoreTool::class, [
        'snapshot_id' => $snapshot->id,
        'database_server_id' => $server->id,
        'schema_name' => 'restore_target',
    ]);

    $response->assertHasErrors();
});

test('get job status returns status info', function () {
    $user = User::factory()->create();
    $server = createDatabaseServer(['name' => 'Status Server']);
    $snapshot = Snapshot::factory()->forServer($server)->create([
        'database_name' => 'status_db',
    ]);

    $response = DatabasementServer::actingAs($user)->tool(GetJobStatusTool::class, [
        'job_id' => $snapshot->backup_job_id,
    ]);

    $response->assertOk()
        ->assertSee('completed')
        ->assertSee('status_db');
});

test('web mcp route requires authentication', function () {
    $this->postJson('/mcp')->assertUnauthorized();
});
