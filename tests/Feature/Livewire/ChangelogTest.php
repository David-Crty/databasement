<?php

use App\Livewire\Changelog;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('changelog'))->assertRedirect(route('login'));
});

test('any member can view the changelog without a specific ability', function () {
    $this->actingAs(User::factory()->withAbilities([])->create())
        ->get(route('changelog'))
        ->assertOk()
        ->assertSeeLivewire(Changelog::class);
});

test('the changelog file is rendered as html', function () {
    $html = $this->actingAs(User::factory()->withAbilities([])->create())
        ->get(route('changelog'))
        ->getContent();

    // The oldest release is in every version of the file.
    expect($html)->toContain('1.0.0')
        // Markdown converted, not printed as source.
        ->toContain('<h2>')
        ->toContain('<li>')
        ->not->toContain('## [');
});
