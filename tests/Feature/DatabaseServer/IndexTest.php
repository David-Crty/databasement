<?php

use App\Enums\Ability;
use App\Livewire\DatabaseServer\Index;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use Livewire\Livewire;

test('displays database servers in table', function () {
    // Viewing needs no ability — an org member with zero grants can list servers.
    $user = User::factory()->withAbilities([])->create();

    DatabaseServer::factory()->create([
        'name' => 'Production MySQL Server',
        'host' => 'localhost',
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Production MySQL Server')
        ->assertSee('localhost');
});

test('shows empty state when no servers exist', function () {
    // Viewing needs no ability — an org member with zero grants can list servers.
    $user = User::factory()->withAbilities([])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('No database servers yet');
});

test('can search database servers', function () {
    // Viewing needs no ability — an org member with zero grants can list servers.
    $user = User::factory()->withAbilities([])->create();

    DatabaseServer::factory()->create(['name' => 'Production MySQL']);
    DatabaseServer::factory()->create(['name' => 'Development PostgreSQL']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Production')
        ->assertSee('Production MySQL')
        ->assertDontSee('Development PostgreSQL');
});

test('can sort by column', function () {
    // Viewing needs no ability — an org member with zero grants can list servers.
    $user = User::factory()->withAbilities([])->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class);

    // Default sorting
    expect($component->get('sortBy'))
        ->toBeArray()
        ->toHaveKey('column')
        ->toHaveKey('direction');

    expect($component->get('sortBy')['column'])->toBe('created_at');
    expect($component->get('sortBy')['direction'])->toBe('desc');
});

test('displays pagination when many servers exist', function () {
    // Viewing needs no ability — an org member with zero grants can list servers.
    $user = User::factory()->withAbilities([])->create();
    DatabaseServer::factory()->count(15)->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class);

    expect($component->viewData('servers')->hasPages())->toBeTrue();
});

test('can delete database server', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $server = DatabaseServer::factory()->create(['name' => 'Test Server']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmDelete', $server->id)
        ->assertSet('deleteId', $server->id)
        ->call('delete')
        ->assertSet('deleteId', null);

    $this->assertDatabaseMissing('database_servers', [
        'id' => $server->id,
    ]);
});

test('runBackupAll dispatches backup jobs for all backup configs on the server', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $server = DatabaseServer::factory()->create(['database_names' => ['mydb']]);

    // Add a second backup config with its own selected database
    \App\Models\Backup::factory()->selected(['other_db'])->for($server)->create();
    $server->refresh();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackupAll', $server->id);

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessBackupJob::class, 2);

    // Each snapshot should be tied to its respective backup config
    $snapshots = \App\Models\Snapshot::all();
    expect($snapshots)->toHaveCount(2);

    $backupIds = $server->backups->pluck('id')->sort()->values();
    $snapshotBackupIds = $snapshots->pluck('backup_id')->sort()->values();
    expect($snapshotBackupIds->all())->toBe($backupIds->all());
});

test('user can toggle backups for a server from the index', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $server = DatabaseServer::factory()->create(['backups_enabled' => true]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('toggleBackupsEnabled', $server->id);

    expect($server->fresh()->backups_enabled)->toBeFalse();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('toggleBackupsEnabled', $server->id);

    expect($server->fresh()->backups_enabled)->toBeTrue();
});

test('index snapshot badge only counts completed snapshots', function () {
    // Viewing needs no ability — an org member with zero grants can list servers.
    $user = User::factory()->withAbilities([])->create();
    $server = DatabaseServer::factory()->create();

    Snapshot::factory()->forServer($server)->completed()->create();
    Snapshot::factory()->forServer($server)->completed()->create();
    Snapshot::factory()->forServer($server)->failed()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSeeHtml('data-tip="View snapshots"')
        ->assertSeeHtml('<span>2</span>');
});
