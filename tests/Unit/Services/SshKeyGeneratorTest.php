<?php

use App\Services\SshKeyGenerator;

beforeEach(function () {
    $this->generator = new SshKeyGenerator;
});

test('generate returns OpenSSH ed25519 keypair with comment', function () {
    $result = $this->generator->generate('databasement:my-server');

    expect($result)->toHaveKeys(['private', 'public']);
    expect($result['private'])->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----');
    expect($result['private'])->toContain('-----END OPENSSH PRIVATE KEY-----');
    expect($result['public'])->toMatch('/^ssh-ed25519 [A-Za-z0-9+\/=]+ databasement:my-server$/');
});

test('generate produces different keypairs each call', function () {
    $a = $this->generator->generate('databasement:a');
    $b = $this->generator->generate('databasement:a');

    expect($a['private'])->not->toBe($b['private']);
    expect($a['public'])->not->toBe($b['public']);
});

test('buildComment slugifies the server name', function () {
    expect($this->generator->buildComment('My Prod DB', 'irrelevant'))
        ->toBe('databasement:my-prod-db');
});

test('buildComment falls back to host when name is blank', function () {
    expect($this->generator->buildComment('', 'bastion.example.com'))
        ->toBe('databasement:bastion-example-com');

    expect($this->generator->buildComment('   ', 'bastion.example.com'))
        ->toBe('databasement:bastion-example-com');
});

test('buildComment falls back to a random token when both are blank', function () {
    $comment = $this->generator->buildComment('', '');

    expect($comment)->toMatch('/^databasement:[a-z0-9]{8}$/');
});

test('buildComment falls back to a random token when source slugifies to empty', function () {
    expect($this->generator->buildComment('!!!', 'irrelevant'))
        ->toMatch('/^databasement:[a-z0-9]{8}$/')
        ->and($this->generator->buildComment('', '...'))
        ->toMatch('/^databasement:[a-z0-9]{8}$/');

});

test('public key stays single-line even with whitespace in comment', function () {
    $result = $this->generator->generate("multi\nline\tcomment");

    expect(substr_count($result['public'], "\n"))->toBe(0);
});
