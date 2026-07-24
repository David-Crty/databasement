<?php

use App\Enums\Ability;
use App\Livewire\ScheduledRestore\Index;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Scheduled restores gate on operate-restores; the happy-path tests below
    // act as the allow case for that ability.
    $this->user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    actingAs($this->user);
});

test('lists existing scheduled restores', function () {
    $scheduled = createScheduledRestore(['name' => 'Nightly staging refresh']);

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
    createScheduledRestore(['name' => 'alpha refresh']);
    createScheduledRestore(['name' => 'beta refresh']);

    Livewire::test(Index::class)
        ->set('search', 'alpha')
        ->assertSee('alpha refresh')
        ->assertDontSee('beta refresh');
});

test('runNow dispatches the restores:run artisan command', function () {
    Artisan::spy();

    $scheduled = createScheduledRestore();
    Snapshot::factory()->forServer($scheduled->sourceServer)->create(['database_name' => 'app']);

    Livewire::test(Index::class)
        ->call('runNow', $scheduled->id);

    Artisan::shouldHaveReceived('call')
        ->with('restores:run', ['scheduledRestore' => $scheduled->id])
        ->once();
});

test('deleteScheduledRestore removes the record', function () {
    $scheduled = createScheduledRestore();

    Livewire::test(Index::class)
        ->call('confirmDelete', $scheduled->id)
        ->call('deleteScheduledRestore');

    expect(ScheduledRestore::find($scheduled->id))->toBeNull();
});

test('without operate-restores, creating a scheduled restore is forbidden', function () {
    actingAs(User::factory()->withAbilities([])->create());

    Livewire::test(Index::class)
        ->call('openCreate')
        ->assertForbidden();
});

test('enabled filter narrows the list', function () {
    createScheduledRestore(['name' => 'active task', 'enabled' => true]);
    createScheduledRestore(['name' => 'paused task', 'enabled' => false]);

    Livewire::test(Index::class)
        ->set('enabledFilter', '0')
        ->assertSee('paused task')
        ->assertDontSee('active task');
});

test('an unrecognized sort column falls back to the default instead of reaching the query', function () {
    createScheduledRestore(['name' => 'visible task']);

    Livewire::test(Index::class)
        ->set('sortBy', ['column' => 'name); drop table users; --', 'direction' => 'asc'])
        ->assertOk()
        ->assertSee('visible task');
});
