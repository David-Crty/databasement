<?php

use App\Enums\UserRole;
use App\Livewire\Restore\Index;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::Admin]);
    actingAs($this->user);
});

function makeRestore(array $attrs = []): Restore
{
    $snapshot = $attrs['snapshot'] ?? Snapshot::factory()->withFile()->create();
    $target = $attrs['target'] ?? DatabaseServer::factory()->create([
        'database_type' => $snapshot->database_type,
    ]);
    $job = BackupJob::create(['status' => $attrs['status'] ?? 'completed']);

    return Restore::create([
        'backup_job_id' => $job->id,
        'snapshot_id' => $snapshot->id,
        'target_server_id' => $target->id,
        'schema_name' => $attrs['schema_name'] ?? 'restored_db',
    ]);
}

test('lists existing restores', function () {
    $restore = makeRestore(['schema_name' => 'visible_schema']);

    Livewire::test(Index::class)
        ->assertSee('visible_schema')
        ->assertSee($restore->targetServer->name);
});

test('openNewRestore dispatches the modal in from-restore-index mode', function () {
    Livewire::test(Index::class)
        ->call('openNewRestore')
        ->assertDispatched('open-restore-modal', mode: 'from-restore-index');
});

test('search filters by schema name', function () {
    makeRestore(['schema_name' => 'alpha_schema']);
    makeRestore(['schema_name' => 'beta_schema']);

    Livewire::test(Index::class)
        ->set('search', 'alpha')
        ->assertSee('alpha_schema')
        ->assertDontSee('beta_schema');
});

test('target server filter narrows the list', function () {
    $a = DatabaseServer::factory()->create(['name' => 'AlphaTarget']);
    $b = DatabaseServer::factory()->create(['name' => 'BetaTarget']);
    makeRestore(['target' => $a, 'schema_name' => 'alpha_only']);
    makeRestore(['target' => $b, 'schema_name' => 'beta_only']);

    Livewire::test(Index::class)
        ->set('targetServerFilter', $a->id)
        ->assertSee('alpha_only')
        ->assertDontSee('beta_only');
});

test('source server filter narrows the list', function () {
    $sourceA = DatabaseServer::factory()->create(['name' => 'SourceA']);
    $sourceB = DatabaseServer::factory()->create(['name' => 'SourceB']);
    $snapA = Snapshot::factory()->forServer($sourceA)->withFile()->create();
    $snapB = Snapshot::factory()->forServer($sourceB)->withFile()->create();
    makeRestore(['snapshot' => $snapA, 'schema_name' => 'from_a']);
    makeRestore(['snapshot' => $snapB, 'schema_name' => 'from_b']);

    Livewire::test(Index::class)
        ->set('sourceServerFilter', $sourceA->id)
        ->assertSee('from_a')
        ->assertDontSee('from_b');
});

test('dbType filter narrows the list', function () {
    $mysqlSource = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $mysqlSnap = Snapshot::factory()->forServer($mysqlSource)->withFile()->create();
    makeRestore(['snapshot' => $mysqlSnap, 'schema_name' => 'mysql_restore']);

    $pgSource = DatabaseServer::factory()->create(['database_type' => 'postgres']);
    $pgSnap = Snapshot::factory()->forServer($pgSource)->withFile()->create();
    makeRestore(['snapshot' => $pgSnap, 'schema_name' => 'pg_restore']);

    Livewire::test(Index::class)
        ->set('dbTypeFilter', 'mysql')
        ->assertSee('mysql_restore')
        ->assertDontSee('pg_restore');
});

test('can delete a restore', function () {
    $restore = makeRestore();

    Livewire::test(Index::class)
        ->call('confirmDeleteRestore', $restore->id)
        ->assertSet('deleteRestoreId', $restore->id)
        ->call('deleteRestore');

    expect(Restore::find($restore->id))->toBeNull();
});

test('mount opens logs modal when valid job ID is in URL', function () {
    $restore = makeRestore();

    Livewire::withQueryParams(['job' => $restore->job->id])
        ->test(Index::class)
        ->assertSet('showLogsModal', true)
        ->assertSet('selectedJobId', $restore->job->id);
});

test('viewer cannot open the new restore modal', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    actingAs($viewer);

    Livewire::test(Index::class)
        ->call('openNewRestore')
        ->assertForbidden();
});
