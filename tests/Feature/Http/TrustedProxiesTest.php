<?php

use App\Models\User;

/**
 * @param  array<string, string>  $server
 */
function healthDebugFrom(string $remoteAddr, array $server = []): \Illuminate\Testing\TestResponse
{
    // An explicit http:// URL, so that "secure" can only come from a trusted
    // X-Forwarded-Proto header and not from the scheme of an earlier request.
    return test()->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr, ...$server])
        ->getJson('http://localhost'.route('health.debug', absolute: false));
}

test('proxies in the default private ranges are trusted', function () {
    healthDebugFrom('10.0.0.5', ['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->assertOk()
        ->assertJsonPath('is_trusted_proxy', true)
        ->assertJsonPath('secure', true);
});

test('the configured trusted proxies replace the default ranges', function () {
    config(['trustedproxy.proxies' => '203.0.113.10']);

    healthDebugFrom('203.0.113.10', ['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->assertOk()
        ->assertJsonPath('is_trusted_proxy', true)
        ->assertJsonPath('secure', true);

    // A private address is no longer a trusted proxy once the list is narrowed,
    // so its forwarded headers must be ignored.
    healthDebugFrom('10.0.0.5', ['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->assertOk()
        ->assertJsonPath('is_trusted_proxy', false)
        ->assertJsonPath('secure', false);
});

test('no proxy is trusted when the list is empty', function () {
    config(['trustedproxy.proxies' => null]);

    healthDebugFrom('10.0.0.5', ['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->assertOk()
        ->assertJsonPath('is_trusted_proxy', false)
        ->assertJsonPath('secure', false);
});
