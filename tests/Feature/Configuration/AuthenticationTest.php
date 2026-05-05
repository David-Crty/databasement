<?php

use App\Livewire\Configuration\Authentication;
use App\Models\User;
use Livewire\Livewire;

test('authentication page displays SSO environment variables', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($user)
        ->test(Authentication::class)
        ->assertSee('Configuration')
        ->assertSee('OAUTH_GOOGLE_ENABLED')
        ->assertSee('OAUTH_GITHUB_ENABLED')
        ->assertSee('OAUTH_GITLAB_ENABLED')
        ->assertSee('OAUTH_OIDC_ENABLED');
});
