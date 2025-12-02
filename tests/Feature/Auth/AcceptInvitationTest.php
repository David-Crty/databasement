<?php

use App\Livewire\Auth\AcceptInvitation;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('invitation page displays for valid token', function () {
    $user = User::factory()->create([
        'password' => null,
        'invitation_token' => Str::random(64),
        'invitation_accepted_at' => null,
    ]);

    $this->get(route('invitation.accept', $user->invitation_token))
        ->assertStatus(200);
});

test('invitation page returns 404 for invalid token', function () {
    $this->get(route('invitation.accept', 'invalid-token'))
        ->assertStatus(404);
});

test('invitation page returns 404 for already accepted invitation', function () {
    $user = User::factory()->create([
        'invitation_token' => Str::random(64),
        'invitation_accepted_at' => now(),
    ]);

    $this->get(route('invitation.accept', $user->invitation_token))
        ->assertStatus(404);
});

test('user can accept invitation with valid password', function () {
    $token = Str::random(64);
    $user = User::factory()->create([
        'password' => null,
        'invitation_token' => $token,
        'invitation_accepted_at' => null,
    ]);

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('accept')
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->invitation_token)->toBeNull();
    expect($user->invitation_accepted_at)->not->toBeNull();
    expect($user->password)->not->toBeNull();
});

test('password confirmation must match', function () {
    $token = Str::random(64);
    User::factory()->create([
        'password' => null,
        'invitation_token' => $token,
        'invitation_accepted_at' => null,
    ]);

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'differentpassword')
        ->call('accept')
        ->assertHasErrors(['password']);
});

test('password must be at least 8 characters', function () {
    $token = Str::random(64);
    User::factory()->create([
        'password' => null,
        'invitation_token' => $token,
        'invitation_accepted_at' => null,
    ]);

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('accept')
        ->assertHasErrors(['password']);
});
