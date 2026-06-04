<?php

use App\Enums\UserRole;
use App\Facades\AppConfig;
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

test('runBackup triggers backup for a specific backup configuration', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('runBackup includes backup display label in success toast', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id);

    // Verify a backup job was created
    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('runBackup fails with authorization error if user is viewer', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id)
        ->assertForbidden();
});

// --- openAdminer ---

test('openAdminer is forbidden when adminer is disabled', function () {
    AppConfig::set('app.adminer_enabled', false);

    $user = User::factory()->create(['role' => UserRole::Admin]);
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertForbidden();
});

test('openAdminer is forbidden for users below required role', function () {
    AppConfig::set('app.adminer_enabled', true);
    AppConfig::set('app.adminer_role', 'admin');

    $user = User::factory()->create(['role' => UserRole::Member]);
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertForbidden();
});

test('openAdminer dispatches modal for users meeting required role', function () {
    AppConfig::set('app.adminer_enabled', true);
    AppConfig::set('app.adminer_role', 'member');

    $user = User::factory()->create(['role' => UserRole::Member]);
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertDispatched('open-adminer-modal');
});

test('openAdminer is forbidden for unsupported database types', function (string $factoryState) {
    AppConfig::set('app.adminer_enabled', true);

    $user = User::factory()->create(['role' => UserRole::Admin]);
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
    AppConfig::set('app.adminer_enabled', true);

    $user = User::factory()->create(['role' => UserRole::Admin]);
    $server = DatabaseServer::factory()->withSshTunnel()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertForbidden();
});
