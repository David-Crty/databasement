<?php

use App\Jobs\ProcessBackupJob;
use App\Jobs\ProcessRestoreJob;
use App\Mcp\Servers\DatabasementServer;
use App\Mcp\Tools\GetJobStatusTool;
use App\Mcp\Tools\ListDatabaseServersTool;
use App\Mcp\Tools\ListSnapshotsTool;
use App\Mcp\Tools\TriggerBackupTool;
use App\Mcp\Tools\TriggerRestoreTool;
use App\Models\BackupJob;
use App\Models\Restore;
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

    Queue::assertPushed(ProcessBackupJob::class);
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

test('list database servers includes backup configuration', function () {
    $user = User::factory()->create();
    $server = createDatabaseServer(['name' => 'Configured Server', 'backups_enabled' => true]);

    $response = DatabasementServer::actingAs($user)->tool(ListDatabaseServersTool::class);

    $response->assertOk()
        ->assertSee('Configured Server')
        ->assertSee('Backups: 1')
        ->assertSee('cron:')
        ->assertSee('Backups enabled: yes');
});

test('list database servers shows unconfigured backup', function () {
    $user = User::factory()->create();

    \App\Models\DatabaseServer::create([
        'name' => 'No Backup Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
    ]);

    $response = DatabasementServer::actingAs($user)->tool(ListDatabaseServersTool::class);

    $response->assertOk()
        ->assertSee('No Backup Server')
        ->assertSee('Backups: not configured');
});

test('list snapshots returns empty message when none exist', function () {
    $user = User::factory()->create();

    $response = DatabasementServer::actingAs($user)->tool(ListSnapshotsTool::class);

    $response->assertOk()
        ->assertSee('No snapshots found');
});

test('list snapshots includes snapshot details', function () {
    $user = User::factory()->create();
    $server = createDatabaseServer(['name' => 'Detail Server']);
    $snapshot = Snapshot::factory()->forServer($server)->create([
        'database_name' => 'detail_db',
    ]);

    $response = DatabasementServer::actingAs($user)->tool(ListSnapshotsTool::class);

    $response->assertOk()
        ->assertSee('detail_db')
        ->assertSee('Detail Server')
        ->assertSee('Status:')
        ->assertSee('Volume:');
});

test('trigger backup returns snapshot ids', function () {
    Queue::fake();
    $user = User::factory()->create();
    $server = createDatabaseServer(['database_names' => ['db1', 'db2']]);

    $response = DatabasementServer::actingAs($user)->tool(TriggerBackupTool::class, [
        'database_server_id' => $server->id,
    ]);

    $response->assertOk()
        ->assertSee('Job ID:')
        ->assertSee('Use get-job-status');
});

test('trigger backup rejects server without backup config', function () {
    Queue::fake();
    $user = User::factory()->create();

    // Create server without the factory's afterCreating hook
    $server = \App\Models\DatabaseServer::create([
        'name' => 'No Backup Server',
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'secret',
        'database_names' => ['testdb'],
        'database_selection_mode' => 'selected',
    ]);

    $response = DatabasementServer::actingAs($user)->tool(TriggerBackupTool::class, [
        'database_server_id' => $server->id,
    ]);

    $response->assertHasErrors();
});

test('trigger backup targets a specific backup id when provided', function () {
    Queue::fake();
    $user = User::factory()->create();
    $server = createDatabaseServer(['database_type' => 'mysql']);

    // Add a second backup on a weekly schedule so there are two to choose from
    $weekly = \App\Models\BackupSchedule::firstOrCreate(['name' => 'Weekly'], ['expression' => '0 3 * * 0']);
    $secondBackup = \App\Models\Backup::factory()->for($server)->create([
        'backup_schedule_id' => $weekly->id,
        'database_selection_mode' => \App\Enums\DatabaseSelectionMode::Selected->value,
        'database_names' => ['mydb'],
    ]);

    $response = DatabasementServer::actingAs($user)->tool(TriggerBackupTool::class, [
        'database_server_id' => $server->id,
        'backup_id' => $secondBackup->id,
    ]);

    $response->assertOk();

    $snapshot = Snapshot::first();
    expect($snapshot?->backup_id)->toBe($secondBackup->id);
});

test('trigger backup rejects a backup id that does not belong to the server', function () {
    $user = User::factory()->create();
    $server = createDatabaseServer(['database_type' => 'mysql']);

    // Create a Backup on a *different* server
    $otherServer = createDatabaseServer(['database_type' => 'mysql']);
    $otherBackup = $otherServer->backups->first();

    $response = DatabasementServer::actingAs($user)->tool(TriggerBackupTool::class, [
        'database_server_id' => $server->id,
        'backup_id' => $otherBackup->id,
    ]);

    $response->assertHasErrors();
});

test('get job status shows restore job details', function () {
    $user = User::factory()->create();
    $server = createDatabaseServer(['name' => 'Restore Server', 'database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($server)->create([
        'database_name' => 'restore_db',
    ]);

    $job = BackupJob::create([
        'status' => 'completed',
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'duration_ms' => 300000,
    ]);

    Restore::create([
        'backup_job_id' => $job->id,
        'snapshot_id' => $snapshot->id,
        'target_server_id' => $server->id,
        'schema_name' => 'restored_schema',
    ]);

    $response = DatabasementServer::actingAs($user)->tool(GetJobStatusTool::class, [
        'job_id' => $job->id,
    ]);

    $response->assertOk()
        ->assertSee('Type: restore')
        ->assertSee('restore_db')
        ->assertSee('Restore Server')
        ->assertSee('Started:')
        ->assertSee('Completed:')
        ->assertSee('Duration:');
});

test('get job status shows error message for failed jobs', function () {
    $user = User::factory()->create();
    $server = createDatabaseServer();
    $snapshot = Snapshot::factory()->forServer($server)->create();

    $snapshot->job->update([
        'status' => 'failed',
        'started_at' => now()->subMinutes(1),
        'completed_at' => now(),
        'duration_ms' => 60000,
        'error_message' => 'Connection refused',
    ]);

    $response = DatabasementServer::actingAs($user)->tool(GetJobStatusTool::class, [
        'job_id' => $snapshot->backup_job_id,
    ]);

    $response->assertOk()
        ->assertSee('failed')
        ->assertSee('Error: Connection refused');
});

test('web mcp route requires authentication', function () {
    $this->postJson('/mcp')->assertUnauthorized();
});
