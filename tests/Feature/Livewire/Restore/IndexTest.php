<?php

use App\Enums\Ability;
use App\Livewire\Restore\Index;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Restores gate on operate-restores; the happy-path tests below act as the
    // allow case for that ability.
    $this->user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
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

test('rerunRestore dispatches the modal pre-filled with the original restore id', function () {
    $restore = makeRestore();

    Livewire::test(Index::class)
        ->call('rerunRestore', $restore->id)
        ->assertDispatched('open-restore-modal', mode: 'from-restore-index', restoreId: $restore->id);
});

test('search filters by schema name', function () {
    makeRestore(['schema_name' => 'alpha_schema']);
    makeRestore(['schema_name' => 'beta_schema']);

    Livewire::test(Index::class)
        ->set('search', 'alpha')
        ->assertSee('alpha_schema')
        ->assertDontSee('beta_schema');
});

test('search filters by restore id', function () {
    $needle = makeRestore(['schema_name' => 'needle_schema']);
    makeRestore(['schema_name' => 'haystack_schema']);

    Livewire::test(Index::class)
        ->set('search', $needle->id)
        ->assertSee('needle_schema')
        ->assertDontSee('haystack_schema');
});

test('search filters by source snapshot id', function () {
    $snapshot = Snapshot::factory()->withFile()->create();
    makeRestore(['snapshot' => $snapshot, 'schema_name' => 'needle_schema']);
    makeRestore(['schema_name' => 'haystack_schema']);

    Livewire::test(Index::class)
        ->set('search', $snapshot->id)
        ->assertSee('needle_schema')
        ->assertDontSee('haystack_schema');
});

test('source cell links to snapshot index pre-filtered by snapshot id', function () {
    $snapshot = Snapshot::factory()->withFile()->create();
    makeRestore(['snapshot' => $snapshot]);

    Livewire::test(Index::class)
        ->assertSee(route('snapshots.index', ['search' => $snapshot->id]), escape: false);
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

test('?job= from another org renders the logs modal with source/target server context when user is a member', function () {
    // The user is a member of OtherOrg but currently active in the default org.
    // Following a notification deeplink to OtherOrg's job should still render
    // the source/target server context in the logs modal, plus a warning that
    // the job belongs to a different org.
    $otherOrg = \App\Models\Organization::factory()->create(['name' => 'OtherOrg']);
    attachUserToOrg($this->user, $otherOrg, 'member');

    $current = app(\App\Services\CurrentOrganization::class);
    $current->set($otherOrg);
    $otherOrgServer = DatabaseServer::factory()->create([
        'name' => 'CrossOrgServer',
        'organization_id' => $otherOrg->id,
    ]);
    $snapshot = Snapshot::factory()->forServer($otherOrgServer)->withFile()->create();
    $restore = makeRestore([
        'snapshot' => $snapshot,
        'target' => $otherOrgServer,
        'schema_name' => 'cross_org_schema',
    ]);

    $current->set(\App\Models\Organization::default());

    Livewire::withQueryParams(['job' => $restore->job->id])
        ->test(Index::class)
        ->assertSet('showLogsModal', true)
        ->assertSee('CrossOrgServer')
        ->assertSee('cross_org_schema')
        ->assertSee('OtherOrg');
});

test('?job= from another org is forbidden when the user is not a member of that org', function () {
    $otherOrg = \App\Models\Organization::factory()->create(['name' => 'OtherOrg']);

    $current = app(\App\Services\CurrentOrganization::class);
    $current->set($otherOrg);
    $otherOrgServer = DatabaseServer::factory()->create(['organization_id' => $otherOrg->id]);
    $snapshot = Snapshot::factory()->forServer($otherOrgServer)->withFile()->create();
    $restore = makeRestore(['snapshot' => $snapshot, 'target' => $otherOrgServer]);

    // Return to the default org. The user is NOT a member of OtherOrg.
    $current->set(\App\Models\Organization::default());

    Livewire::withQueryParams(['job' => $restore->job->id])
        ->test(Index::class)
        ->assertForbidden();
});

test('without operate-restores, opening a new restore is forbidden', function () {
    actingAs(User::factory()->withAbilities([])->create());

    Livewire::test(Index::class)
        ->call('openNewRestore')
        ->assertForbidden();
});
