<?php

use App\Livewire\Auth\AcceptInvitation;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

test('toast helpers are not part of a component public API', function (string $method) {
    $user = User::factory()->create([
        'password' => null,
        'invitation_token' => Str::random(64),
        'invitation_accepted_at' => null,
    ]);

    $component = Livewire::test(AcceptInvitation::class, ['token' => $user->invitation_token]);

    expect(fn () => $component->call($method, 'title', 'description'))
        ->toThrow(MethodNotFoundException::class);
})->with(['toast', 'success', 'warning', 'error', 'info']);
