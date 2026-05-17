<?php

use App\Enums\UserRole;
use App\Livewire\ScheduledRestore\Index;
use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::Admin]);
    actingAs($this->user);
});

function makeScheduled(array $attrs = []): ScheduledRestore
{
    $source = $attrs['source'] ?? DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['app']]);
    $target = $attrs['target'] ?? DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['target']]);

    return ScheduledRestore::factory()->create(array_merge([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
        'source_database_name' => 'app',
        'schema_name' => 'restored_db',
    ], array_diff_key($attrs, ['source' => null, 'target' => null])));
}

test('lists existing scheduled restores', function () {
    $scheduled = makeScheduled(['name' => 'Nightly staging refresh']);

    Livewire::test(Index::class)
        ->assertSee('Nightly staging refresh')
        ->assertSee($scheduled->targetServer->name);
});

test('openCreate dispatches the modal open event', function () {
    Livewire::test(Index::class)
        ->call('openCreate')
        ->assertDispatched('open-scheduled-restore-modal');
});

test('search filters by name', function () {
    makeScheduled(['name' => 'alpha refresh']);
    makeScheduled(['name' => 'beta refresh']);

    Livewire::test(Index::class)
        ->set('search', 'alpha')
        ->assertSee('alpha refresh')
        ->assertDontSee('beta refresh');
});

test('toggleEnabled flips the enabled flag', function () {
    $scheduled = makeScheduled(['enabled' => true]);

    Livewire::test(Index::class)
        ->call('toggleEnabled', $scheduled->id);

    expect($scheduled->fresh()->enabled)->toBeFalse();
});

test('runNow dispatches the restores:run artisan command', function () {
    Artisan::spy();

    $scheduled = makeScheduled();
    Snapshot::factory()->forServer($scheduled->sourceServer)->create(['database_name' => 'app']);

    Livewire::test(Index::class)
        ->call('runNow', $scheduled->id);

    Artisan::shouldHaveReceived('call')
        ->with('restores:run', ['scheduledRestore' => $scheduled->id])
        ->once();
});

test('deleteScheduledRestore removes the record', function () {
    $scheduled = makeScheduled();

    Livewire::test(Index::class)
        ->call('confirmDelete', $scheduled->id)
        ->call('deleteScheduledRestore');

    expect(ScheduledRestore::find($scheduled->id))->toBeNull();
});

test('non-admin users cannot create scheduled restores', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    actingAs($viewer);

    Livewire::test(Index::class)
        ->call('openCreate')
        ->assertForbidden();
});

test('enabled filter narrows the list', function () {
    makeScheduled(['name' => 'active task', 'enabled' => true]);
    makeScheduled(['name' => 'paused task', 'enabled' => false]);

    Livewire::test(Index::class)
        ->set('enabledFilter', '0')
        ->assertSee('paused task')
        ->assertDontSee('active task');
});
