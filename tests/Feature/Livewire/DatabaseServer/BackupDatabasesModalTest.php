<?php

use App\Enums\Ability;
use App\Enums\DatabaseSelectionMode;
use App\Jobs\ProcessBackupJob;
use App\Livewire\DatabaseServer\BackupDatabasesModal;
use App\Models\Agent;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

function openBackupDatabasesModal(DatabaseServer $server, ?User $user = null): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs($user ?? User::factory()->withAbilities([Ability::RunBackups->value])->create())
        ->test(BackupDatabasesModal::class)
        ->call('openModal', $server->id)
        ->call('loadDatabases');
}

test('run-backups allows backing up only the selected databases of a configuration', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['app', 'users', 'orders'])->create();

    openBackupDatabasesModal($server)
        ->assertSet("configurations.{$backup->id}.options", [
            ['id' => 'app', 'name' => 'app'],
            ['id' => 'users', 'name' => 'users'],
            ['id' => 'orders', 'name' => 'orders'],
        ])
        ->set("selections.{$backup->id}", ['users'])
        ->call('backup')
        ->assertSet('showModal', false);

    expect(Snapshot::sole()->database_name)->toBe('users');
    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('without run-backups, opening the modal is forbidden', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();

    Livewire::actingAs(User::factory()->withAllAbilitiesExcept(Ability::RunBackups->value)->create())
        ->test(BackupDatabasesModal::class)
        ->call('openModal', $server->id)
        ->assertForbidden();
});

test('a directly reachable server lists its databases within the configuration pattern', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->pattern('^prod_')->create();
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->once()
        ->andReturn(['prod_users', 'prod_orders', 'staging_db']);

    openBackupDatabasesModal($server)
        ->assertSet("configurations.{$backup->id}.options", [
            ['id' => 'prod_users', 'name' => 'prod_users'],
            ['id' => 'prod_orders', 'name' => 'prod_orders'],
        ]);
});

test('a server whose databases cannot be listed says so instead of offering any', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->create(['database_selection_mode' => DatabaseSelectionMode::All->value]);
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->andThrow(new RuntimeException('Connection refused'));

    openBackupDatabasesModal($server)
        ->assertSet("configurations.{$backup->id}.options", [])
        ->assertSee('Connection refused');
});

test('an agent-backed server offers the databases its configuration backed up before', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create(['agent_id' => Agent::factory()->create()->id]);
    $backup = Backup::factory()->for($server)->create(['database_selection_mode' => DatabaseSelectionMode::All->value]);
    $failedJob = BackupJob::create(['status' => 'failed']);
    Snapshot::factory()->forServer($server)->create(['database_name' => 'orders']);
    Snapshot::factory()->forServer($server)->create(['database_name' => 'app']);
    Snapshot::factory()->forServer($server)->create(['database_name' => 'app']);
    Snapshot::factory()->forServer($server)->create(['database_name' => '(all databases)', 'backup_job_id' => $failedJob->id]);

    openBackupDatabasesModal($server)
        ->assertSet("configurations.{$backup->id}.source", BackupDatabasesModal::SOURCE_HISTORY)
        ->assertSet("configurations.{$backup->id}.options", [
            ['id' => 'app', 'name' => 'app'],
            ['id' => 'orders', 'name' => 'orders'],
        ]);
});

test('backing up with nothing selected starts nothing', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    Backup::factory()->for($server)->selected(['app'])->create();

    openBackupDatabasesModal($server)
        ->call('backup')
        ->assertHasErrors('selections')
        ->assertSet('showModal', true);

    Queue::assertNothingPushed();
});
