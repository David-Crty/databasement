<?php

use App\Models\OAuthIdentity;
use App\Models\User;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    User::factory()->create();

    $response = $this->get(route('login'));

    $response->assertStatus(200);
});

test('login redirects to register when no users exist', function () {
    $response = $this->get(route('login'));

    $response->assertRedirect(route('register'));
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('oauth only users see helpful error when trying to login with password', function () {
    $user = User::factory()->create([
        'password' => null, // OAuth-only user
    ]);

    // Create an OAuth identity for this user
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_user_id' => 'github-123',
        'email' => $user->email,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'any-password',
    ]);

    $response->assertSessionHasErrors([
        'email' => 'This account uses OAuth login. Please use the OAuth button below to sign in.',
    ]);

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('login screen renders oauth provider button when enabled', function (string $provider, string $label) {
    User::factory()->create();

    config()->set("oauth.providers.{$provider}.enabled", true);
    config()->set("oauth.providers.{$provider}.client_id", 'test');
    config()->set("oauth.providers.{$provider}.client_secret", 'test');

    if ($provider === 'oidc') {
        config()->set('oauth.providers.oidc.base_url', 'https://idp.example.com');
    }

    $response = $this->get(route('login'));

    $response->assertStatus(200);
    $response->assertSeeText("Continue with {$label}");
})->with([
    'google' => ['google', 'Google'],
    'github' => ['github', 'GitHub'],
    'gitlab' => ['gitlab', 'GitLab'],
    'oidc' => ['oidc', 'SSO'],
]);

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
