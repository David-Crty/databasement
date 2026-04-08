<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Config;

test('throws exception for invalid oauth default role', function () {
    Config::set('oauth.default_role', 'invalid_role');
    Config::set('oauth.providers', []);

    $provider = new AppServiceProvider(app());

    expect(fn () => $provider->performOAuthValidation())
        ->toThrow(\InvalidArgumentException::class, "Invalid OAUTH_DEFAULT_ROLE 'invalid_role'. Must be one of: viewer, member, admin");
});

test('throws exception when enabled provider is missing credentials', function () {
    Config::set('oauth.default_role', 'member');
    Config::set('oauth.providers.github', [
        'enabled' => true,
        'client_id' => null,
        'client_secret' => 'some-secret',
    ]);

    $provider = new AppServiceProvider(app());

    expect(fn () => $provider->performOAuthValidation())
        ->toThrow(\InvalidArgumentException::class, "OAuth provider 'github' is enabled but missing client_id or client_secret");
});

test('throws exception when oidc provider is missing base url', function () {
    Config::set('oauth.default_role', 'member');
    Config::set('oauth.providers.oidc', [
        'enabled' => true,
        'client_id' => 'some-id',
        'client_secret' => 'some-secret',
        'base_url' => null,
    ]);

    $provider = new AppServiceProvider(app());

    expect(fn () => $provider->performOAuthValidation())
        ->toThrow(\InvalidArgumentException::class, "OAuth provider 'oidc' is enabled but missing required base URL");
});

test('throws exception when strict mode is enabled without any role mappings', function () {
    Config::set('oauth.default_role', 'member');
    Config::set('oauth.providers.oidc', [
        'enabled' => true,
        'client_id' => 'id',
        'client_secret' => 'secret',
        'base_url' => 'https://idp.example.com',
    ]);
    Config::set('oauth.role_mapping', [
        'claim' => 'groups',
        'admin' => '',
        'member' => '',
        'viewer' => '',
        'strict' => true,
    ]);

    $provider = new AppServiceProvider(app());

    expect(fn () => $provider->performOAuthValidation())
        ->toThrow(\InvalidArgumentException::class, 'OAUTH_OIDC_ROLE_STRICT is enabled but no role mappings are configured');
});

test('does not throw when strict mode is enabled with role mappings', function () {
    Config::set('oauth.default_role', 'member');
    Config::set('oauth.providers.oidc', [
        'enabled' => true,
        'client_id' => 'id',
        'client_secret' => 'secret',
        'base_url' => 'https://idp.example.com',
    ]);
    Config::set('oauth.role_mapping', [
        'claim' => 'groups',
        'admin' => 'my-admins',
        'member' => '',
        'viewer' => '',
        'strict' => true,
    ]);

    $provider = new AppServiceProvider(app());

    expect(fn () => $provider->performOAuthValidation())->not->toThrow(\InvalidArgumentException::class);
});

test('does not throw strict mode error when oidc is disabled', function () {
    Config::set('oauth.default_role', 'member');
    Config::set('oauth.providers', []);
    Config::set('oauth.role_mapping', [
        'claim' => 'groups',
        'admin' => '',
        'member' => '',
        'viewer' => '',
        'strict' => true,
    ]);

    $provider = new AppServiceProvider(app());

    expect(fn () => $provider->performOAuthValidation())->not->toThrow(\InvalidArgumentException::class);
});
