<?php

use App\Rules\SafeUsername;
use Illuminate\Support\Facades\Validator;

test('SafeUsername accepts or rejects a login name', function (string $username, bool $valid) {
    $passes = Validator::make(
        ['username' => $username],
        ['username' => [new SafeUsername]],
    )->passes();

    expect($passes)->toBe($valid);
})->with([
    'plain' => ['deploy', true],
    'with dash inside' => ['back-up', true],
    'dots and underscores' => ['ubuntu.1_x', true],
    'kerberos realm' => ['user@corp.example.com', true],
    'windows domain' => ['CORP\\operator', true],

    // ssh reads a leading dash as an option rather than a login name, which
    // reaches ProxyCommand and from there a shell.
    'leading dash' => ['-oProxyCommand=id', false],
    'whitespace' => ['deploy user', false],
    'non-ascii' => ['déploy', false],
    // `$` would match before a final newline, so the pattern anchors with \z.
    'trailing newline' => ["deploy\n", false],
]);
