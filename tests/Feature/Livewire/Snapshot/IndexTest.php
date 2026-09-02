<?php

use App\Enums\Ability;
use App\Enums\BackupJobStatus;
use App\Livewire\Snapshot\Index;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // The Snapshot index gates cancelling/deleting on delete-snapshots and
    // restoring on operate-restores. The default actor holds exactly those, so
    // the happy-path tests below double as the allow cases for both abilities.
    $this->user = User::factory()->withAbilities([
        Ability::DeleteSnapshots->value,
        Ability::OperateRestores->value,
    ])->create();
    actingAs($this->user);
});

test('lists snapshots with completed jobs', function () {
    $snapshot = Snapshot::factory()->withFile()->create(['database_name' => 'visible_db']);

    Livewire::test(Index::class)
        ->assertSee('visible_db')
        ->assertSee($snapshot->databaseServer->name);
});

test('shows pending and running snapshot rows as in-progress', function () {
    // Use BackupJobFactory which creates the snapshot with a pending job (matches production flow).
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshots = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual');
    $pending = $snapshots[0];

    Livewire::test(Index::class)
        ->assertSee($pending->database_name)
        ->assertSee('Pending');
});

test('search filters by database name', function () {
    Snapshot::factory()->withFile()->create(['database_name' => 'users_db']);
    Snapshot::factory()->withFile()->create(['database_name' => 'orders_db']);

    Livewire::test(Index::class)
        ->set('search', 'users')
        ->assertSee('users_db')
        ->assertDontSee('orders_db');
});

test('can sort by job status', function () {
    Snapshot::factory()->withFile()->create(['database_name' => 'completed_db']);
    $failedJob = BackupJob::create(['status' => 'failed']);
    Snapshot::factory()->withFile()->create([
        'database_name' => 'failed_db',
        'backup_job_id' => $failedJob->id,
    ]);

    $component = Livewire::test(Index::class)
        ->set('sortBy', ['column' => 'status', 'direction' => 'asc']);

    $names = $component->viewData('snapshots')->pluck('database_name')->all();
    expect($names)->toBe(['completed_db', 'failed_db']);

    $component->set('sortBy', ['column' => 'status', 'direction' => 'desc']);
    $names = $component->viewData('snapshots')->pluck('database_name')->all();
    expect($names)->toBe(['failed_db', 'completed_db']);
});

test('search filters by snapshot id', function () {
    $needle = Snapshot::factory()->withFile()->create(['database_name' => 'needle_db']);
    Snapshot::factory()->withFile()->create(['database_name' => 'haystack_db']);

    Livewire::test(Index::class)
        ->set('search', $needle->id)
        ->assertSee('needle_db')
        ->assertDontSee('haystack_db');
});

test('server filter narrows the list', function () {
    $a = DatabaseServer::factory()->create(['name' => 'AlphaServer']);
    $b = DatabaseServer::factory()->create(['name' => 'BetaServer']);
    Snapshot::factory()->forServer($a)->withFile()->create(['database_name' => 'alpha_db']);
    Snapshot::factory()->forServer($b)->withFile()->create(['database_name' => 'beta_db']);

    Livewire::test(Index::class)
        ->set('serverFilter', $a->id)
        ->assertSee('alpha_db')
        ->assertDontSee('beta_db');
});

test('dbType filter narrows the list', function () {
    $mysqlServer = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    Snapshot::factory()->forServer($mysqlServer)->withFile()->create(['database_name' => 'mysql_db']);
    $pgServer = DatabaseServer::factory()->create(['database_type' => 'postgres']);
    Snapshot::factory()->forServer($pgServer)->withFile()->create(['database_name' => 'pg_db']);

    Livewire::test(Index::class)
        ->set('dbTypeFilter', 'mysql')
        ->assertSee('mysql_db')
        ->assertDontSee('pg_db');
});

test('fileMissing filter shows only snapshots with missing files', function () {
    Snapshot::factory()->withFile()->create(['database_name' => 'present_db']);
    Snapshot::factory()->fileMissing()->create(['database_name' => 'gone_db']);

    Livewire::test(Index::class)
        ->set('fileMissing', '1')
        ->assertSee('gone_db')
        ->assertDontSee('present_db');
});

test('triggerRestore dispatches open-restore-modal with from-snapshot mode', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    Livewire::test(Index::class)
        ->call('triggerRestore', $snapshot->id)
        ->assertDispatched('open-restore-modal', mode: 'from-snapshot', snapshotId: $snapshot->id);
});

test('can cancel a pending backup job', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshots = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual');
    $job = $snapshots[0]->job;

    Livewire::test(Index::class)
        ->call('confirmCancelJob', $job->id)
        ->assertSet('cancelJobId', $job->id)
        ->call('deletePendingJob');

    expect(BackupJob::find($job->id))->toBeNull();
});

test('cannot cancel a pending backup job from another organization', function () {
    // A pending job whose snapshot belongs to a server in another org. The
    // policy resolves the owning org via snapshot → server.
    $otherOrg = \App\Models\Organization::factory()->create();
    $server = DatabaseServer::factory()->create(['organization_id' => $otherOrg->id]);
    $job = Snapshot::factory()->forServer($server)->create()->job;
    $job->update(['status' => BackupJobStatus::Pending]);

    // Acting as the default-org admin (beforeEach); the job belongs to $otherOrg,
    // so even with the delete-snapshots ability the cancel must be forbidden.
    Livewire::test(Index::class)
        ->call('confirmCancelJob', $job->id)
        ->assertForbidden();

    expect(BackupJob::find($job->id))->not->toBeNull();
});

test('cannot cancel a non-pending job', function () {
    $snapshot = Snapshot::factory()->withFile()->create();
    // Default factory creates a completed job.
    expect($snapshot->job->status)->toBe(BackupJobStatus::Completed);

    Livewire::test(Index::class)
        ->call('confirmCancelJob', $snapshot->job->id)
        ->assertForbidden();
});

test('canDelete reflects snapshot status and lock state', function () {
    $completedUnlocked = Snapshot::factory()->withFile()->create();
    $completedLocked = Snapshot::factory()->withFile()->create(['locked' => true]);
    $pending = Snapshot::factory()->withFile()->create();
    $pending->job->update(['status' => BackupJobStatus::Pending, 'completed_at' => null]);

    $component = Livewire::test(Index::class)->instance();

    expect($component->canDelete($completedUnlocked))->toBeTrue()
        ->and($component->canDelete($completedLocked))->toBeFalse()
        ->and($component->canDelete($pending))->toBeFalse();
});

test('can delete a completed snapshot', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    Livewire::test(Index::class)
        ->call('confirmDeleteSnapshot', $snapshot->id)
        ->assertSet('deleteSnapshotId', $snapshot->id)
        ->call('deleteSnapshot');

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('delete-snapshots allows locking and unlocking a snapshot', function () {
    $snapshot = Snapshot::factory()->withFile()->create();
    $user = User::factory()->withAbilities([Ability::DeleteSnapshots->value])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('toggleLock', $snapshot->id);

    expect($snapshot->fresh()->locked)->toBeTrue();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('toggleLock', $snapshot->id);

    expect($snapshot->fresh()->locked)->toBeFalse();
});

test('without delete-snapshots, toggling a snapshot lock is forbidden', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    actingAs(User::factory()->withAllAbilitiesExcept(Ability::DeleteSnapshots->value)->create());

    Livewire::test(Index::class)
        ->call('toggleLock', $snapshot->id)
        ->assertForbidden();

    expect($snapshot->fresh()->locked)->toBeFalse();
});

test('a locked snapshot cannot be deleted even with delete-snapshots', function () {
    $snapshot = Snapshot::factory()->withFile()->create(['locked' => true]);

    Livewire::test(Index::class)
        ->call('confirmDeleteSnapshot', $snapshot->id)
        ->assertForbidden();

    expect(Snapshot::find($snapshot->id))->not->toBeNull();
});

test('a snapshot locked between the authorize check and the delete call is not deleted', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    $component = Livewire::test(Index::class)
        ->call('confirmDeleteSnapshot', $snapshot->id)
        ->assertSet('deleteSnapshotId', $snapshot->id);

    // Simulate a concurrent request locking the snapshot in the window between
    // deleteSnapshot()'s authorize() check and its actual delete call: apply the
    // lock as soon as deleteSnapshot() fetches the snapshot, before authorize()
    // reads the (now stale, in-memory) locked value.
    Snapshot::retrieved(function (Snapshot $retrieved) use ($snapshot) {
        if ($retrieved->id === $snapshot->id) {
            DB::table('snapshots')->where('id', $snapshot->id)->update(['locked' => true]);
        }
    });

    $component->call('deleteSnapshot');

    expect(Snapshot::find($snapshot->id))->not->toBeNull()
        ->and(Snapshot::find($snapshot->id)->locked)->toBeTrue();
});

test('a snapshot deleted by another request before the row lock is skipped without error', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    $component = Livewire::test(Index::class)
        ->call('confirmDeleteSnapshot', $snapshot->id)
        ->assertSet('deleteSnapshotId', $snapshot->id);

    // Simulate a concurrent request that finishes deleting the snapshot in the
    // window between this request's initial findOrFail() and its own row-locked
    // reload: remove the row (cascading its files/restores at the DB level, same
    // as a real delete) as soon as deleteSnapshot() re-fetches it, before the
    // transaction's lockForUpdate() runs. If deleteSnapshot() deleted the stale,
    // pre-lock instance instead of reloading, its `deleting` callback would
    // dereference a `job` relation whose row is untouched here but would be gone
    // in the real race, and the request would fail instead of no-op-ing.
    Snapshot::retrieved(function (Snapshot $retrieved) use ($snapshot) {
        if ($retrieved->id === $snapshot->id) {
            DB::table('snapshots')->where('id', $snapshot->id)->delete();
        }
    });

    $component->call('deleteSnapshot');

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('a snapshot deleted by another request before toggleLock reloads it does not report success', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    // Mount first so the index listing's own render (which hydrates every visible
    // snapshot, including this one) happens before the listener below is armed.
    $component = Livewire::test(Index::class);

    // Simulate a concurrent request that deletes the snapshot in the window
    // between toggleLock()'s initial findOrFail() and its own row-locked reload.
    Snapshot::retrieved(function (Snapshot $retrieved) use ($snapshot) {
        if ($retrieved->id === $snapshot->id) {
            DB::table('snapshots')->where('id', $snapshot->id)->delete();
        }
    });

    $component->call('toggleLock', $snapshot->id);

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('locking then deleting a snapshot in sequence still succeeds', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    $component = Livewire::test(Index::class)
        ->call('toggleLock', $snapshot->id);

    expect($snapshot->fresh()->locked)->toBeTrue();

    $component->call('toggleLock', $snapshot->id);

    expect($snapshot->fresh()->locked)->toBeFalse();

    $component->call('confirmDeleteSnapshot', $snapshot->id)
        ->call('deleteSnapshot');

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('without delete-snapshots, deleting a snapshot is forbidden', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    actingAs(User::factory()->withAbilities([])->create());

    Livewire::test(Index::class)
        ->call('confirmDeleteSnapshot', $snapshot->id)
        ->assertForbidden();

    expect(Snapshot::find($snapshot->id))->not->toBeNull();
});

test('without delete-snapshots, cancelling a pending job is forbidden', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $job = app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual')[0]->job;

    actingAs(User::factory()->withAbilities([])->create());

    Livewire::test(Index::class)
        ->call('confirmCancelJob', $job->id)
        ->assertForbidden();

    expect(BackupJob::find($job->id))->not->toBeNull();
});

test('without operate-restores, triggering a restore is forbidden', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    actingAs(User::factory()->withAbilities([])->create());

    Livewire::test(Index::class)
        ->call('triggerRestore', $snapshot->id)
        ->assertForbidden();
});

test('mount opens logs modal when valid job ID is in URL', function () {
    $snapshot = Snapshot::factory()->withFile()->create();

    Livewire::withQueryParams(['job' => $snapshot->job->id])
        ->test(Index::class)
        ->assertSet('showLogsModal', true)
        ->assertSet('selectedJobId', $snapshot->job->id);
});

test('mount handles invalid job ID gracefully', function () {
    Livewire::withQueryParams(['job' => 'invalid_id'])
        ->test(Index::class)
        ->assertSet('showLogsModal', false)
        ->assertSet('selectedJobId', null);
});

test('clear resets all filters', function () {
    Livewire::test(Index::class)
        ->set('search', 'foo')
        ->set('statusFilter', 'completed')
        ->set('serverFilter', 'x')
        ->set('dbTypeFilter', 'mysql')
        ->set('fileMissing', '1')
        ->call('clear')
        ->assertSet('search', '')
        ->assertSet('statusFilter', '')
        ->assertSet('serverFilter', '')
        ->assertSet('dbTypeFilter', '')
        ->assertSet('fileMissing', '');
});

test('download-snapshots allows opening the copy picker for a multi-volume snapshot', function () {
    $user = User::factory()->withAbilities([Ability::DownloadSnapshots->value])->create();

    $volumeA = Volume::factory()->local()->create(['name' => 'Primary Local']);
    $volumeB = Volume::factory()->local()->create(['name' => 'Offsite Local']);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $server->backups->first()->volumes()->sync([$volumeA->id, $volumeB->id]);

    $snapshot = app(BackupJobFactory::class)
        ->createSnapshots($server->backups->first()->fresh(), 'manual', $user->id)[0];
    $snapshot->update(['filename' => 'multi.sql.gz']);
    $snapshot->files()->update(['status' => \App\Enums\SnapshotFileStatus::Completed]);
    $snapshot->job->markCompleted();

    $fileOnA = $snapshot->files()->where('volume_id', $volumeA->id)->firstOrFail();
    $fileOnB = $snapshot->files()->where('volume_id', $volumeB->id)->firstOrFail();

    // Both copies are offered, each linking to its own volume's download.
    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openDownloadModal', $snapshot->id)
        ->assertSet('showDownloadModal', true)
        ->assertSet('downloadSnapshotId', $snapshot->id)
        ->assertSee('Primary Local')
        ->assertSee('Offsite Local')
        ->assertSee(route('snapshots.download', [$snapshot, 'file' => $fileOnA->id]), false)
        ->assertSee(route('snapshots.download', [$snapshot, 'file' => $fileOnB->id]), false);
});

test('without download-snapshots, opening the copy picker is forbidden', function () {
    $user = User::factory()->withAllAbilitiesExcept(Ability::DownloadSnapshots->value)->create();
    $snapshot = Snapshot::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openDownloadModal', $snapshot->id)
        ->assertForbidden();
});

test('?job= from another org opens the logs modal when the user is a member', function () {
    // Both Snapshot and Restore are organization-scoped, so BackupJobPolicy has
    // to bypass those scopes to work out who owns a job. Without that, a member
    // following a notification deeplink into their other org is denied.
    $otherOrg = \App\Models\Organization::factory()->create(['name' => 'OtherOrg']);
    attachUserToOrg($this->user, $otherOrg, 'member');

    $current = app(\App\Services\CurrentOrganization::class);
    $current->set($otherOrg);
    $server = DatabaseServer::factory()->create(['organization_id' => $otherOrg->id]);
    $job = Snapshot::factory()->forServer($server)->create()->job;
    $current->set(\App\Models\Organization::default());

    Livewire::withQueryParams(['job' => $job->id])
        ->test(Index::class)
        ->assertSet('showLogsModal', true)
        ->assertSet('selectedJobId', $job->id);
});

test('?job= from another org is forbidden when the user is not a member', function () {
    $otherOrg = \App\Models\Organization::factory()->create();
    $server = DatabaseServer::factory()->create(['organization_id' => $otherOrg->id]);
    $job = Snapshot::factory()->forServer($server)->create()->job;

    Livewire::withQueryParams(['job' => $job->id])
        ->test(Index::class)
        ->assertForbidden();
});
