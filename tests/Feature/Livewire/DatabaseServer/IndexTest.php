<?php

use App\Enums\Ability;
use App\Jobs\ProcessBackupJob;
use App\Livewire\DatabaseServer\Index;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

// --- runBackup (run-backups) ---

test('run-backups allows triggering a backup for a specific configuration', function () {
    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('without run-backups, runBackup is forbidden', function () {
    $user = User::factory()->withAbilities([])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id)
        ->assertForbidden();
});

// --- delete (manage-database-servers) ---

test('manage-database-servers allows deleting a server', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmDelete', $server->id)
        ->assertSet('showDeleteModal', true)
        ->call('delete');

    $this->assertDatabaseMissing('database_servers', ['id' => $server->id]);
});

test('without manage-database-servers, deleting a server is forbidden', function () {
    $user = User::factory()->withAbilities([])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmDelete', $server->id)
        ->assertForbidden();
});

test('without manage-database-servers, toggling backups is forbidden', function () {
    $user = User::factory()->withAbilities([])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('toggleBackupsEnabled', $server->id)
        ->assertForbidden();
});

// --- restore (operate-restores) ---

test('operate-restores allows starting a restore from a server', function () {
    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmRestore', $server->id)
        ->assertDispatched('open-restore-modal', mode: 'from-server', targetServerId: $server->id);
});

test('without operate-restores, starting a restore is forbidden', function () {
    $user = User::factory()->withAbilities([])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmRestore', $server->id)
        ->assertForbidden();
});

// --- openAdminer (use-adminer) ---

test('use-adminer allows opening the Adminer modal', function () {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertDispatched('open-adminer-modal');
});

test('without use-adminer, openAdminer is forbidden', function () {
    $user = User::factory()->withAbilities([])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertForbidden();
});

test('when Adminer is globally disabled, openAdminer is forbidden even with use-adminer', function () {
    \App\Facades\AppConfig::set('app.adminer_enabled', false);

    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertForbidden();
});

test('openAdminer is forbidden for unsupported database types', function (string $factoryState) {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->{$factoryState}()->withoutBackups()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertForbidden();
})->with([
    'redis' => ['redis'],
    'mongodb' => ['mongodb'],
]);

test('openAdminer is forbidden for servers using SSH', function () {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->withSshTunnel()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertForbidden();
});
