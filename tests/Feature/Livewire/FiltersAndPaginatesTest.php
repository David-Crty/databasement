<?php

use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The filter plumbing shared by every paginated index page. Covered once here
 * rather than in each component's own test file.
 */
beforeEach(function () {
    actingAs(User::factory()->superAdmin()->create());
});

test('changing a filter returns to the first page', function (string $component, string $filter) {
    Livewire::test($component)
        ->set('paginators.page', 3)
        ->set($filter, 'anything')
        ->assertSet('paginators.page', 1);
})->with([
    'restores' => [App\Livewire\Restore\Index::class, 'statusFilter'],
    'scheduled restores' => [App\Livewire\ScheduledRestore\Index::class, 'sourceServerFilter'],
    'snapshots' => [App\Livewire\Snapshot\Index::class, 'serverFilter'],
    'database servers' => [App\Livewire\DatabaseServer\Index::class, 'search'],
    'volumes' => [App\Livewire\Volume\Index::class, 'search'],
    'agents' => [App\Livewire\Agent\Index::class, 'search'],
    'users' => [App\Livewire\User\Index::class, 'roleFilter'],
]);

test('changing non-filter state leaves the current page alone', function () {
    // Deleting from page 3 should not bounce the user back to page 1.
    Livewire::test(App\Livewire\Volume\Index::class)
        ->set('paginators.page', 3)
        ->set('showDeleteModal', true)
        ->assertSet('paginators.page', 3);
});

test('clear resets every filter and returns to the first page', function () {
    Livewire::test(App\Livewire\Restore\Index::class)
        ->set('search', 'x')
        ->set('statusFilter', 'completed')
        ->set('sourceServerFilter', 'a')
        ->set('targetServerFilter', 'b')
        ->set('dbTypeFilter', 'mysql')
        ->set('paginators.page', 3)
        ->call('clear')
        ->assertSet('search', '')
        ->assertSet('statusFilter', '')
        ->assertSet('sourceServerFilter', '')
        ->assertSet('targetServerFilter', '')
        ->assertSet('dbTypeFilter', '')
        ->assertSet('paginators.page', 1);
});
