<?php

use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

function enableOidcProvider(): void
{
    Config::set('oauth.providers.oidc.enabled', true);
    Config::set('oauth.providers.oidc.client_id', 'test-client');
    Config::set('oauth.providers.oidc.client_secret', 'test-secret');
    Config::set('oauth.providers.oidc.base_url', 'https://idp.example.com');
}

function fakeOidcUser(string $id, string $email, string $name = 'OIDC User', ?array $groups = null): SocialiteUser
{
    $raw = ['sub' => $id, 'name' => $name, 'email' => $email];
    if ($groups !== null) {
        $raw['groups'] = $groups;
    }

    return (new SocialiteUser)->setRaw($raw)->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'nickname' => $name,
    ])->setToken('fake-token');
}

beforeEach(function () {
    Config::set('oauth.providers.github.enabled', true);
    Config::set('oauth.auto_link_by_email', true);
    Config::set('oauth.auto_create_users', true);
    Config::set('oauth.default_role', 'member');
    Config::set('oauth.role_mapping.claim', 'groups');
    Config::set('oauth.role_mapping.admin', '');
    Config::set('oauth.role_mapping.member', '');
    Config::set('oauth.role_mapping.viewer', '');
    Config::set('oauth.role_mapping.strict', false);
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

    expect(User::count())->toBe(1);

    $existingUser->refresh();
    expect($existingUser->oauthIdentities)->toHaveCount(1)
        ->and($existingUser->role)->toBe(User::ROLE_ADMIN) // unchanged
        ->and($existingUser->password)->toBeNull(); // cleared to enforce OAuth-only login
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

// -------------------------------------------------------
// OIDC Group-Based Role Mapping
// -------------------------------------------------------

test('oidc role mapping assigns admin role from matching group', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'databasement-admins');

    Socialite::fake('oidc', fakeOidcUser('oidc-1', 'admin@example.com', 'Admin', ['databasement-admins']));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'admin@example.com')->first();
    expect($user->role)->toBe(User::ROLE_ADMIN);
});

test('oidc role mapping assigns member role from matching group', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.member', 'databasement-members');

    Socialite::fake('oidc', fakeOidcUser('oidc-2', 'member@example.com', 'Member', ['databasement-members']));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'member@example.com')->first();
    expect($user->role)->toBe(User::ROLE_MEMBER);
});

test('oidc role mapping assigns viewer role from matching group', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.viewer', 'databasement-viewers');

    Socialite::fake('oidc', fakeOidcUser('oidc-3', 'viewer@example.com', 'Viewer', ['databasement-viewers']));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'viewer@example.com')->first();
    expect($user->role)->toBe(User::ROLE_VIEWER);
});

test('oidc role mapping uses highest priority role when multiple groups match', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'databasement-admins');
    Config::set('oauth.role_mapping.member', 'databasement-members');

    Socialite::fake('oidc', fakeOidcUser('oidc-4', 'multi@example.com', 'Multi', ['databasement-members', 'databasement-admins']));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'multi@example.com')->first();
    expect($user->role)->toBe(User::ROLE_ADMIN);
});

test('oidc role mapping falls back to default role when no group matches and strict is off', function () {
    enableOidcProvider();
    Config::set('oauth.default_role', 'viewer');
    Config::set('oauth.role_mapping.admin', 'databasement-admins');

    Socialite::fake('oidc', fakeOidcUser('oidc-5', 'fallback@example.com', 'Fallback', ['unrelated-group']));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'fallback@example.com')->first();
    expect($user->role)->toBe(User::ROLE_VIEWER);
});

test('oidc role mapping denies access when no group matches and strict is on', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'databasement-admins');
    Config::set('oauth.role_mapping.strict', true);

    Socialite::fake('oidc', fakeOidcUser('oidc-6', 'denied@example.com', 'Denied', ['unrelated-group']));

    $response = $this->get(route('oauth.callback', 'oidc'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error', 'Your account is not authorized to access this application.');
    expect(User::where('email', 'denied@example.com')->exists())->toBeFalse();
});

test('oidc role mapping syncs role for returning user', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'databasement-admins');

    $user = User::factory()->create(['role' => User::ROLE_MEMBER]);
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'provider_user_id' => 'oidc-7',
        'email' => $user->email,
    ]);

    Socialite::fake('oidc', fakeOidcUser('oidc-7', $user->email, $user->name, ['databasement-admins']));

    $this->get(route('oauth.callback', 'oidc'));

    $user->refresh();
    expect($user->role)->toBe(User::ROLE_ADMIN);
});

test('oidc role mapping updates role when groups change for returning user', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'databasement-admins');
    Config::set('oauth.role_mapping.viewer', 'databasement-viewers');

    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'provider_user_id' => 'oidc-8',
        'email' => $user->email,
    ]);

    // User lost admin group, now only has viewer group
    Socialite::fake('oidc', fakeOidcUser('oidc-8', $user->email, $user->name, ['databasement-viewers']));

    $this->get(route('oauth.callback', 'oidc'));

    $user->refresh();
    expect($user->role)->toBe(User::ROLE_VIEWER);
});

test('oidc role mapping uses default role when no mapping is configured', function () {
    enableOidcProvider();
    Config::set('oauth.default_role', 'viewer');
    // All role_mapping values are empty (from beforeEach)

    Socialite::fake('oidc', fakeOidcUser('oidc-9', 'default@example.com', 'Default', ['some-group']));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'default@example.com')->first();
    expect($user->role)->toBe(User::ROLE_VIEWER);
});

test('oidc role mapping falls back to default role when groups claim is missing', function () {
    enableOidcProvider();
    Config::set('oauth.default_role', 'viewer');
    Config::set('oauth.role_mapping.admin', 'databasement-admins');

    Socialite::fake('oidc', fakeOidcUser('oidc-no-groups', 'noclaimuser@example.com', 'No Groups'));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'noclaimuser@example.com')->first();
    expect($user->role)->toBe(User::ROLE_VIEWER);
});

test('oidc role mapping strict mode denies access when groups claim is missing', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'databasement-admins');
    Config::set('oauth.role_mapping.strict', true);

    Socialite::fake('oidc', fakeOidcUser('oidc-no-groups-strict', 'denied-noclaim@example.com', 'No Groups'));

    $response = $this->get(route('oauth.callback', 'oidc'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error', 'Your account is not authorized to access this application.');
    expect(User::where('email', 'denied-noclaim@example.com')->exists())->toBeFalse();
});

test('oidc role mapping reads from custom claim name', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.claim', 'roles');
    Config::set('oauth.role_mapping.admin', 'super-admin');

    $raw = ['sub' => 'oidc-10', 'name' => 'Custom', 'email' => 'custom@example.com', 'roles' => ['super-admin']];
    $socialiteUser = (new SocialiteUser)->setRaw($raw)->map([
        'id' => 'oidc-10',
        'name' => 'Custom',
        'email' => 'custom@example.com',
        'nickname' => 'Custom',
    ])->setToken('fake-token');

    Socialite::fake('oidc', $socialiteUser);

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'custom@example.com')->first();
    expect($user->role)->toBe(User::ROLE_ADMIN);
});

test('oidc role mapping does not apply to non-oidc providers', function () {
    Config::set('oauth.role_mapping.admin', 'databasement-admins');
    Config::set('oauth.default_role', 'member');

    Socialite::fake('github', (new SocialiteUser)->setRaw([
        'sub' => 'gh-role',
        'groups' => ['databasement-admins'],
    ])->map([
        'id' => 'gh-role',
        'name' => 'GitHub User',
        'email' => 'ghuser@example.com',
        'nickname' => 'ghuser',
    ])->setToken('token'));

    $this->get(route('oauth.callback', 'github'));

    $user = User::where('email', 'ghuser@example.com')->first();
    expect($user->role)->toBe(User::ROLE_MEMBER);
});

test('oidc role mapping supports comma-separated groups in env var', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'super-admins, databasement-admins, ops-team');

    Socialite::fake('oidc', fakeOidcUser('oidc-12', 'comma@example.com', 'Comma', ['ops-team']));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'comma@example.com')->first();
    expect($user->role)->toBe(User::ROLE_ADMIN);
});

test('oidc role mapping matches groups case-insensitively', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'Databasement-Admins');

    Socialite::fake('oidc', fakeOidcUser('oidc-case', 'case@example.com', 'Case', ['databasement-admins']));

    $this->get(route('oauth.callback', 'oidc'));

    $user = User::where('email', 'case@example.com')->first();
    expect($user->role)->toBe(User::ROLE_ADMIN);
});

test('oidc role mapping strict mode denies returning user with revoked groups', function () {
    enableOidcProvider();
    Config::set('oauth.role_mapping.admin', 'databasement-admins');
    Config::set('oauth.role_mapping.strict', true);

    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    OAuthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'provider_user_id' => 'oidc-13',
        'email' => $user->email,
    ]);

    // User's groups have been revoked in IdP
    Socialite::fake('oidc', fakeOidcUser('oidc-13', $user->email, $user->name, ['unrelated']));

    $response = $this->get(route('oauth.callback', 'oidc'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error', 'Your account is not authorized to access this application.');
});
