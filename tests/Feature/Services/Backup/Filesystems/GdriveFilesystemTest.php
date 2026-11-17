<?php

use App\Exceptions\Backup\FilesystemException;
use App\Services\Backup\Filesystems\GdriveFilesystem;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\Filesystem;

/**
 * @param  array<int, array<string, mixed>>  $history
 */
function gdriveFilesystemRespondingWith(Response $tokenResponse, array &$history = []): GdriveFilesystem
{
    $stack = HandlerStack::create(new MockHandler([$tokenResponse]));
    $stack->push(Middleware::history($history));

    // Google's own default client does not throw on HTTP errors either.
    return new GdriveFilesystem(new GuzzleClient(['handler' => $stack, 'http_errors' => false]));
}

function gdriveConfig(): array
{
    return [
        'client_id' => 'client-id.apps.googleusercontent.com',
        'client_secret' => 'client-secret',
        'refresh_token' => 'refresh-token',
        'folder_id' => '',
    ];
}

test('handles only the gdrive type', function () {
    $filesystem = new GdriveFilesystem;

    expect($filesystem->handles('gdrive'))->toBeTrue()
        ->and($filesystem->handles('GDRIVE'))->toBeTrue()
        ->and($filesystem->handles('s3'))->toBeFalse()
        ->and($filesystem->handles(null))->toBeFalse();
});

test('exchanges the trimmed refresh token for an access token', function () {
    $history = [];
    $filesystem = gdriveFilesystemRespondingWith(
        new Response(200, [], json_encode(['access_token' => 'ya29.token', 'expires_in' => 3599, 'token_type' => 'Bearer'])),
        $history,
    );

    $pastedWithWhitespace = array_map(fn (string $value) => " {$value}\n", gdriveConfig());

    expect($filesystem->get($pastedWithWhitespace))->toBeInstanceOf(Filesystem::class)
        ->and($history)->toHaveCount(1);

    parse_str((string) $history[0]['request']->getBody(), $body);

    expect($body)->toMatchArray([
        'grant_type' => 'refresh_token',
        'refresh_token' => 'refresh-token',
        'client_id' => 'client-id.apps.googleusercontent.com',
    ]);
});

test('fails with the reason Google gives when the refresh token is rejected', function () {
    $filesystem = gdriveFilesystemRespondingWith(
        new Response(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'])),
    );

    expect(fn () => $filesystem->get(gdriveConfig()))->toThrow(
        FilesystemException::class,
        'Google Drive authentication failed (invalid_grant: Token has been expired or revoked.)',
    );
});
