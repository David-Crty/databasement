<?php

use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('oauth.providers.github.enabled', true);
    Config::set('oauth.auto_link_by_email', true);
    Config::set('oauth.auto_create_users', true);
    Config::set('oauth.default_role', 'member');
});

test('oauth redirect returns 404 for disabled provider', function () {
    Config::set('oauth.providers.github.enabled', false);

    $response = $this->get(route('oauth.redirect', 'github'));

    $response->assertNotFound();
});

test('oauth redirect works for enabled provider', function () {
    Socialite::fake('github');

    $response = $this->get(route('oauth.redirect', 'github'));

    $response->assertRedirect();
});

test('oauth callback creates new user when email not found', function () {
    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'github-123',
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'nickname' => 'newuser',
        'avatar' => 'https://example.com/avatar.jpg',
    ])->setToken('fake-token')
        ->setRefreshToken('fake-refresh-token')
        ->setExpiresIn(3600));

    $response = $this->get(route('oauth.callback', 'github'));

    $response->assertRedirect(route('dashboard'));

    $user = User::where('email', 'newuser@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('New User')
        ->and($user->role)->toBe(User::ROLE_MEMBER)
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->invitation_accepted_at)->not->toBeNull();

    // Check OAuth identity was created
    expect($user->oauthIdentities)->toHaveCount(1);
    $identity = $user->oauthIdentities->first();
    expect($identity->provider)->toBe('github')
        ->and($identity->provider_user_id)->toBe('github-123')
        ->and($identity->email)->toBe('newuser@example.com');
});

test('oauth callback links to existing user by email and clears password', function () {
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
        'role' => User::ROLE_ADMIN,
        'password' => 'original-password',
    ]);

    // Verify user has password before OAuth
    expect($existingUser->password)->not->toBeNull();

    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'github-456',
        'name' => 'GitHub Name',
        'email' => 'existing@example.com',
        'nickname' => 'existing',
    ])->setToken('fake-token'));

    $response = $this->get(route('oauth.callback', 'github'));

    $response->assertRedirect(route('dashboard'));

    // Should not create a new user
    expect(User::count())->toBe(1);

    // Should link OAuth identity to existing user
    $existingUser->refresh();
    expect($existingUser->oauthIdentities)->toHaveCount(1)
        ->and($existingUser->role)->toBe(User::ROLE_ADMIN)
        ->and($existingUser->password)->toBeNull();
    // Role unchanged

    // Password should be cleared to enforce OAuth-only login
});

test('oauth callback logs in returning oauth user', function () {
    $user = User::factory()->create();
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_user_id' => 'github-789',
        'email' => $user->email,
    ]);

    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'github-789',
        'name' => $user->name,
        'email' => $user->email,
        'nickname' => 'test',
    ])->setToken('new-token'));

    $response = $this->get(route('oauth.callback', 'github'));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    // Should not create duplicate identity
    expect(OAuthIdentity::count())->toBe(1);
});

test('oauth uses configured default role for new users', function () {
    Config::set('oauth.default_role', 'viewer');

    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'github-viewer',
        'name' => 'Viewer User',
        'email' => 'viewer@example.com',
        'nickname' => 'viewer',
    ])->setToken('token'));

    $this->get(route('oauth.callback', 'github'));

    $user = User::where('email', 'viewer@example.com')->first();
    expect($user->role)->toBe(User::ROLE_VIEWER);
});

test('oauth callback fails when auto-create is disabled and no matching user', function () {
    Config::set('oauth.auto_create_users', false);

    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'github-unknown',
        'name' => 'Unknown User',
        'email' => 'unknown@example.com',
        'nickname' => 'unknown',
    ])->setToken('token'));

    $response = $this->get(route('oauth.callback', 'github'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');

    expect(User::where('email', 'unknown@example.com')->exists())->toBeFalse();
});

test('oauth callback fails when email is not provided', function () {
    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'github-noemail',
        'name' => 'No Email User',
        'email' => null,
        'nickname' => 'noemail',
    ])->setToken('token'));

    $response = $this->get(route('oauth.callback', 'github'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
});

test('oauth callback does not link by email when auto-link is disabled', function () {
    Config::set('oauth.auto_link_by_email', false);

    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'github-new',
        'name' => 'New OAuth User',
        'email' => 'existing@example.com', // Same email as existing user
        'nickname' => 'new',
    ])->setToken('token'));

    $response = $this->get(route('oauth.callback', 'github'));

    // With auto_link_by_email=false, it won't link to existing user
    // Should fail with a helpful error message since email already exists
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error', 'An account with this email already exists. Please log in with your password or contact an administrator.');

    // Verify existing user was not linked
    $existingUser->refresh();
    expect($existingUser->oauthIdentities)->toHaveCount(0);
});

test('user can have multiple oauth providers linked', function () {
    $user = User::factory()->create(['email' => 'multi@example.com']);

    // First OAuth login - GitHub
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_user_id' => 'github-multi',
        'email' => $user->email,
    ]);

    // Enable Google provider
    Config::set('oauth.providers.google.enabled', true);

    // Second OAuth login - Google (same email, should link to same user)
    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => 'google-multi',
        'name' => $user->name,
        'email' => 'multi@example.com',
        'nickname' => 'multi',
    ])->setToken('google-token'));

    $response = $this->get(route('oauth.callback', 'google'));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    // User should now have 2 OAuth identities
    $user->refresh();
    expect($user->oauthIdentities)->toHaveCount(2);

    $providers = $user->oauthIdentities->pluck('provider')->toArray();
    expect($providers)->toContain('github')
        ->and($providers)->toContain('google');
});
