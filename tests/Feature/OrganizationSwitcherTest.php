<?php

use App\Livewire\OrganizationSwitcher;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

test('user can switch to an organization they belong to', function () {
    $user = User::factory()->create();
    $otherOrg = Organization::factory()->create();
    attachUserToOrg($user, $otherOrg, 'member');

    Livewire::actingAs($user)
        ->test(OrganizationSwitcher::class)
        ->call('switchOrg', $otherOrg->id)
        ->assertRedirect(route('dashboard'));
});

test('user cannot switch to an organization they do not belong to', function () {
    $user = User::factory()->create();
    $foreignOrg = Organization::factory()->create();

    Livewire::actingAs($user)
        ->test(OrganizationSwitcher::class)
        ->call('switchOrg', $foreignOrg->id)
        ->assertNoRedirect();
});

test('super admin can switch to any organization', function () {
    $admin = User::factory()->superAdmin()->create();
    $otherOrg = Organization::factory()->create();

    Livewire::actingAs($admin)
        ->test(OrganizationSwitcher::class)
        ->call('switchOrg', $otherOrg->id)
        ->assertRedirect(route('dashboard'));
});
