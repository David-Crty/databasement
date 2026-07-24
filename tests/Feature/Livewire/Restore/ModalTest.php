<?php

use App\Enums\Ability;
use App\Enums\BackupJobStatus;
use App\Jobs\ProcessRestoreJob;
use App\Livewire\Restore\Modal;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Restores gate on operate-restores; the happy-path tests below act as the
    // allow case for that ability.
    $this->user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    actingAs($this->user);

    // Avoid real connection attempts when listing existing databases.
    $mock = Mockery::mock(DatabaseProvider::class);
    $mock->shouldReceive('listDatabasesForServer')->andReturn([]);
    app()->instance(DatabaseProvider::class, $mock);
});

// ============================================================================
// from-server mode
// ============================================================================

test('from-server mode: navigates step 1 -> step 2 by picking a snapshot', function (string $type) {
    $target = DatabaseServer::factory()->create(['database_type' => $type]);
    $source = DatabaseServer::factory()->create(['database_type' => $type]);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $target->id)
        ->assertSet('currentStep', 1)
        ->assertSee($snapshot->database_name)
        ->call('selectSnapshot', $snapshot->id)
        ->assertSet('selectedSnapshotId', $snapshot->id)
        ->assertSet('currentStep', 2);
})->with(['mysql', 'postgres', 'sqlite', 'firebird']);

test('from-server mode: queues restore job and dispatches restore-created', function () {
    Queue::fake();

    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $target->id)
        ->call('selectSnapshot', $snapshot->id)
        ->set('schemaName', 'restored_db')
        ->call('restore')
        ->assertDispatched('restore-created');

    Queue::assertPushed(ProcessRestoreJob::class, 1);

    $restore = \App\Models\Restore::where('snapshot_id', $snapshot->id)
        ->where('target_server_id', $target->id)
        ->first();

    expect($restore)->not->toBeNull()
        ->and($restore->schema_name)->toBe('restored_db')
        ->and($restore->job->status)->toBe(BackupJobStatus::Pending);
});

test('rejects restore when the target server is agent-backed', function () {
    Queue::fake();

    $agent = \App\Models\Agent::factory()->create();
    $agentTarget = DatabaseServer::factory()->create(['database_type' => 'mysql', 'agent_id' => $agent->id]);
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    // Craft a request that bypasses the UI picker, which hides agent-backed servers.
    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-snapshot', snapshotId: $snapshot->id)
        ->set('targetServerId', $agentTarget->id)
        ->set('schemaName', 'restored_db')
        ->call('restore')
        ->assertNotDispatched('restore-created');

    Queue::assertNothingPushed();
    expect(Restore::count())->toBe(0);
});

test('from-server mode: only shows snapshots matching target database type', function () {
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);

    $mysqlServer = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    Snapshot::factory()->forServer($mysqlServer)->withFile()->create();

    $postgresServer = DatabaseServer::factory()->create(['database_type' => 'postgres']);
    Snapshot::factory()->forServer($postgresServer)->withFile()->create();

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $target->id)
        ->assertSee($mysqlServer->name)
        ->assertDontSee($postgresServer->name);
});

test('from-server mode: previousStep clears the selected snapshot', function () {
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $target->id)
        ->call('selectSnapshot', $snapshot->id)
        ->assertSet('currentStep', 2)
        ->call('previousStep')
        ->assertSet('currentStep', 1)
        ->assertSet('selectedSnapshotId', null);
});

test('from-server mode: search filters snapshots', function () {
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    Snapshot::factory()->forServer($source)->withFile()->create(['database_name' => 'users_db']);
    Snapshot::factory()->forServer($source)->withFile()->create(['database_name' => 'orders_db']);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $target->id)
        ->assertSee('users_db')
        ->assertSee('orders_db')
        ->set('snapshotSearch', 'users')
        ->assertSee('users_db')
        ->assertDontSee('orders_db');
});

test('from-server mode: sqlite pre-fills schema with target server database path', function () {
    $target = DatabaseServer::factory()->sqlite()->create([
        'database_names' => ['/data/production.sqlite'],
    ]);
    $source = DatabaseServer::factory()->sqlite()->create();
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $target->id)
        ->call('selectSnapshot', $snapshot->id)
        ->assertSet('schemaName', '/data/production.sqlite');
});

test('from-server mode: prevents restoring over the application database', function () {
    Queue::fake();

    $defaultConnection = config('database.default');

    $target = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
    ]);
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    config([
        "database.connections.{$defaultConnection}.driver" => 'mysql',
        "database.connections.{$defaultConnection}.host" => '127.0.0.1',
        "database.connections.{$defaultConnection}.port" => 3306,
        "database.connections.{$defaultConnection}.database" => 'databasement_app',
    ]);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $target->id)
        ->call('selectSnapshot', $snapshot->id)
        ->set('schemaName', 'databasement_app')
        ->call('restore')
        ->assertNotDispatched('restore-created');

    Queue::assertNotPushed(ProcessRestoreJob::class);
});

// ============================================================================
// from-snapshot mode
// ============================================================================

test('from-snapshot mode: target select lists only servers matching the snapshot db type', function () {
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    $mysqlTarget = DatabaseServer::factory()->create(['database_type' => 'mysql', 'name' => 'MySQL Target']);
    $postgresTarget = DatabaseServer::factory()->create(['database_type' => 'postgres', 'name' => 'Postgres Target']);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-snapshot', snapshotId: $snapshot->id)
        ->assertSet('selectedSnapshotId', $snapshot->id)
        ->assertSet('currentStep', 1)
        ->assertSee('MySQL Target')
        ->assertDontSee('Postgres Target');
});

test('from-snapshot mode: choosing a target prefills the schema and queues restore', function () {
    Queue::fake();

    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create(['database_name' => 'mydb']);
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-snapshot', snapshotId: $snapshot->id)
        ->set('targetServerId', $target->id)
        ->assertSet('schemaName', 'mydb')
        ->set('schemaName', 'restored_db')
        ->call('restore')
        ->assertDispatched('restore-created');

    Queue::assertPushed(ProcessRestoreJob::class, 1);
});

test('from-snapshot mode: is a single step with no back navigation', function () {
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-snapshot', snapshotId: $snapshot->id)
        ->assertSet('currentStep', 1)
        ->assertDontSee(__('Back'))
        ->call('previousStep')
        ->assertSet('currentStep', 1);
});

// ============================================================================
// from-restore-index mode
// ============================================================================

test('from-restore-index mode: walks both steps and queues restore', function () {
    Queue::fake();

    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create(['database_name' => 'app_db']);
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-restore-index')
        ->assertSet('currentStep', 1)
        ->assertSet('selectedSnapshotId', null)
        ->assertSet('targetServer', null)
        ->call('selectSnapshot', $snapshot->id)
        ->assertSet('currentStep', 2)
        ->assertSet('selectedSnapshotId', $snapshot->id)
        ->set('targetServerId', $target->id)
        ->set('schemaName', 'fresh_db')
        ->call('restore')
        ->assertDispatched('restore-created');

    Queue::assertPushed(ProcessRestoreJob::class, 1);
});

test('from-restore-index mode: step 2 only shows target servers compatible with chosen snapshot', function () {
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    $mysqlTarget = DatabaseServer::factory()->create(['database_type' => 'mysql', 'name' => 'MyTargetMy']);
    $postgresTarget = DatabaseServer::factory()->create(['database_type' => 'postgres', 'name' => 'MyTargetPg']);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-restore-index')
        ->call('selectSnapshot', $snapshot->id)
        ->assertSee('MyTargetMy')
        ->assertDontSee('MyTargetPg');
});

test('from-restore-index mode: dbTypeFilter narrows the snapshot list', function () {
    $mysqlServer = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    Snapshot::factory()->forServer($mysqlServer)->withFile()->create(['database_name' => 'mysql_db']);

    $postgresServer = DatabaseServer::factory()->create(['database_type' => 'postgres']);
    Snapshot::factory()->forServer($postgresServer)->withFile()->create(['database_name' => 'pg_db']);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-restore-index')
        ->assertSee('mysql_db')
        ->assertSee('pg_db')
        ->set('dbTypeFilter', 'mysql')
        ->assertSee('mysql_db')
        ->assertDontSee('pg_db');
});

test('changing dbTypeFilter clears the stale serverFilter so results are not over-filtered', function () {
    $mysqlServer = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    Snapshot::factory()->forServer($mysqlServer)->withFile()->create(['database_name' => 'mysql_db']);

    $postgresServer = DatabaseServer::factory()->create(['database_type' => 'postgres']);
    Snapshot::factory()->forServer($postgresServer)->withFile()->create(['database_name' => 'pg_db']);

    // User picks a MySQL server in the source-server filter, then switches the
    // db-type filter to Postgres. The Postgres snapshot should appear (i.e.
    // the now-incompatible serverFilter must have been cleared).
    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-restore-index')
        ->set('serverFilter', $mysqlServer->id)
        ->assertSee('mysql_db')
        ->assertDontSee('pg_db')
        ->set('dbTypeFilter', 'postgres')
        ->assertSet('serverFilter', null)
        ->assertSee('pg_db')
        ->assertDontSee('mysql_db');
});

test('from-restore-index mode: passing restoreId pre-fills snapshot, target, and jumps to step 2', function () {
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create(['database_name' => 'app_db']);
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $job = BackupJob::create(['status' => 'completed']);
    $restore = Restore::create([
        'backup_job_id' => $job->id,
        'snapshot_id' => $snapshot->id,
        'target_server_id' => $target->id,
        'schema_name' => 'previous_schema',
        'options' => ['force_database' => true, 'owner_user' => 'postgres'],
    ]);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-restore-index', restoreId: $restore->id)
        ->assertSet('currentStep', 2)
        ->assertSet('selectedSnapshotId', $snapshot->id)
        ->assertSet('targetServer.id', $target->id)
        ->assertSet('targetServerId', $target->id)
        ->assertSet('schemaName', 'previous_schema')
        ->assertSet('forceDatabase', true)
        ->assertSet('ownerUser', 'postgres');
});

test('from-restore-index mode: previousStep from destination clears target and snapshot', function () {
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-restore-index')
        ->call('selectSnapshot', $snapshot->id)
        ->set('targetServerId', $target->id)
        ->assertSet('currentStep', 2)
        ->assertSet('targetServer.id', $target->id)
        ->call('previousStep')
        ->assertSet('currentStep', 1)
        ->assertSet('targetServer', null)
        ->assertSet('targetServerId', null)
        ->assertSet('selectedSnapshotId', null);
});

// ============================================================================
// Locked field enforcement (#[Locked] blocks client-side mutation)
// ============================================================================

test('cannot mutate selectedSnapshotId from the client', function () {
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();
    $other = Snapshot::factory()->forServer($source)->withFile()->create();

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-snapshot', snapshotId: $snapshot->id)
        ->set('selectedSnapshotId', $other->id);
})->throws(CannotUpdateLockedPropertyException::class);

// ============================================================================
// Authorization
// ============================================================================

test('without operate-restores, starting a restore in from-restore-index mode is forbidden', function () {
    actingAs(User::factory()->withAbilities([])->create());

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-restore-index')
        ->assertForbidden();
});

test('without operate-restores, starting a restore in from-server mode is forbidden', function () {
    actingAs(User::factory()->withAbilities([])->create());

    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-server', targetServerId: $target->id)
        ->assertForbidden();
});

test('without operate-restores, starting a restore in from-snapshot mode is forbidden', function () {
    actingAs(User::factory()->withAbilities([])->create());

    $source = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($source)->withFile()->create();

    Livewire::test(Modal::class)
        ->dispatch('open-restore-modal', mode: 'from-snapshot', snapshotId: $snapshot->id)
        ->assertForbidden();
});
