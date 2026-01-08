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
