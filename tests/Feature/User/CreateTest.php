<?php

use App\Livewire\User\Create;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
});

test('non-admin users cannot access create user page', function () {
    $member = User::factory()->create(['role' => 'member']);
    actingAs($member);

    get(route('users.create'))
        ->assertForbidden();
});

test('viewer cannot access create user page', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    actingAs($viewer);

    get(route('users.create'))
        ->assertForbidden();
});

test('admin can access create user page', function () {
    actingAs($this->admin);

    get(route('users.create'))
        ->assertOk();
});

test('admin can create user with valid data', function () {
    actingAs($this->admin);

    Livewire::test(Create::class)
        ->set('form.name', 'New User')
        ->set('form.email', 'newuser@example.com')
        ->set('form.role', 'member')
        ->call('save')
        ->assertSet('showCopyModal', true)
        ->assertSet('invitationUrl', fn ($url) => str_contains($url, '/invitation/'));

    $user = User::where('email', 'newuser@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('New User');
    expect($user->role)->toBe('member');
    expect($user->invitation_token)->not->toBeNull();
    expect($user->password)->toBeNull();
});
