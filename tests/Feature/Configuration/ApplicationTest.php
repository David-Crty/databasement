<?php

use App\Livewire\Configuration\Application;
use App\Models\User;
use Livewire\Livewire;

test('configuration route redirects to application tab', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->get('/configuration')
        ->assertRedirect(route('configuration.application'));
});

test('application page displays environment variables', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($user)
        ->test(Application::class)
        ->assertSee('Configuration')
        ->assertSee('APP_DEBUG')
        ->assertSee('TZ')
        ->assertSee('TRUSTED_PROXIES');
});
