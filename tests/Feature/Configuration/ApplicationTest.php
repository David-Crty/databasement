<?php

use App\Livewire\Configuration\Application;
use App\Models\User;
use Livewire\Livewire;

test('application page displays environment variables', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($user)
        ->test(Application::class)
        ->assertSee('Configuration')
        ->assertSee('APP_DEBUG')
        ->assertSee('TZ')
        ->assertSee('TRUSTED_PROXIES');
});
