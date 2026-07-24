<?php

use App\Livewire\Configuration\Authentication;
use App\Models\User;
use Livewire\Livewire;

test('authentication page displays SSO environment variables', function () {
    $user = User::factory()->superAdmin()->create();

    Livewire::actingAs($user)
        ->test(Authentication::class)
        ->assertSee('Configuration')
        ->assertSee('OAUTH_ONLY_MODE')
        ->assertSee('OAUTH_GOOGLE_ENABLED')
        ->assertSee('OAUTH_GITHUB_ENABLED')
        ->assertSee('OAUTH_GITLAB_ENABLED')
        ->assertSee('OAUTH_OIDC_ENABLED')
        ->assertSee('OAUTH_DEFAULT_ORGANIZATION_ID');
});

test('non-super-admins can view the authentication config screen', function (string $role) {
    Livewire::actingAs(User::factory()->create(['role' => $role]))
        ->test(Authentication::class)
        ->assertOk()
        ->assertSee('OAUTH_ONLY_MODE');
})->with(['member', 'admin']);
