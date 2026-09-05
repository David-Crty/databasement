<?php

use App\Livewire\Changelog;
use App\Models\User;
use App\Support\Changelog\ChangelogParser;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.version' => null]);

    $this->changelogPath = tempnam(sys_get_temp_dir(), 'changelog-test-');
    file_put_contents($this->changelogPath, <<<'MD'
    # Changelog

    ## [Unreleased]

    ### Added

    - Something not shipped yet

    ## [1.7] - 2026-09-05

    ### Added

    - `1.7.9` Snapshots can carry a comment ([#522](https://github.com/David-Crty/databasement/pull/522))

    ### Security

    - `1.7.8` The Redis password is masked in logged commands

    ## [1.6] - 2026-07-27

    ### Fixed

    - `1.6.12` An older fix

    [Unreleased]: https://github.com/David-Crty/databasement/compare/v1.7.10...HEAD
    [1.7]: https://github.com/David-Crty/databasement/compare/v1.6.12...v1.7.10
    [1.6]: https://github.com/David-Crty/databasement/compare/v1.5.6...v1.6.12
    MD);

    $this->app->instance(ChangelogParser::class, new ChangelogParser($this->changelogPath));
});

afterEach(function () {
    @unlink($this->changelogPath);
});

test('guests are redirected to the login page', function () {
    $this->get(route('changelog'))->assertRedirect(route('login'));
});

test('any member can view the changelog without a specific ability', function () {
    $this->actingAs(User::factory()->withAbilities([])->create())
        ->get(route('changelog'))
        ->assertOk()
        ->assertSeeLivewire(Changelog::class)
        ->assertSee('v1.7')
        ->assertSee('1.7.9')
        ->assertSeeHtml('<a href="https://github.com/David-Crty/databasement/pull/522">#522</a>');
});

test('the minor holding the running version is current, newer minors are new, and unreleased entries are hidden', function () {
    config(['app.version' => 'v1.7.8']);

    Livewire::actingAs(User::factory()->withAbilities([])->create())
        ->test(Changelog::class)
        ->assertSeeInOrder(['v1.7', __('Current'), 'v1.6'])
        ->assertDontSee(__('Unreleased'))
        ->assertDontSee('Something not shipped yet');
});

test('a minor newer than the running version is flagged as new', function () {
    config(['app.version' => 'v1.6.12']);

    Livewire::actingAs(User::factory()->withAbilities([])->create())
        ->test(Changelog::class)
        ->assertSeeInOrder(['v1.7', __('New'), 'v1.6', __('Current')]);
});

test('unreleased entries are shown when no version is configured', function () {
    Livewire::actingAs(User::factory()->withAbilities([])->create())
        ->test(Changelog::class)
        ->assertSee(__('Unreleased'))
        ->assertSee('Something not shipped yet')
        ->assertDontSee(__('Current'));
});

test('the layout links to the changelog', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee(route('changelog'));
});
