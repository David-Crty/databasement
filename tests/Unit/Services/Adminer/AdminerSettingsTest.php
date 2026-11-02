<?php

use App\Services\Adminer\AdminerSettings;

$credentials = [
    'driver' => 'pgsql',
    'server' => 'db.example.com:5432',
    'username' => 'admin',
    'password' => 'secret',
    'db' => 'app',
];

test('injected credentials are handed to Adminer without the token check', function () use ($credentials) {
    $settings = new AdminerSettings($credentials, '/build/adminer.css');

    expect($settings->credentials())->toBe(['db.example.com:5432', 'admin', 'secret'])
        ->and($settings->verifyLoginToken())->toBeFalse();
});

test('without injected credentials Adminer uses its session and checks the token', function () {
    $settings = new AdminerSettings(null, '/build/adminer.css');

    expect($settings->credentials())->toBeNull()
        ->and($settings->verifyLoginToken())->toBeTrue();
});

test('the stylesheet is declared for both colour schemes', function () {
    expect((new AdminerSettings(null, '/build/adminer.css'))->css())->toBe(['/build/adminer.css' => '']);
});
