<?php

use App\Actions\DatabaseServer\UpdateSshConfigAction;
use App\Models\DatabaseServerSshConfig;

test('a credential left blank keeps the stored one', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create(['auth_type' => 'password', 'password' => 'stored']);

    UpdateSshConfigAction::run($sshConfig, ['host' => 'new.example.com', 'auth_type' => 'password', 'password' => '']);

    expect($sshConfig->fresh())->host->toBe('new.example.com')->password->toBe('stored');
});

test('switching to key authentication drops the password', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create(['auth_type' => 'password', 'password' => 'stored']);

    UpdateSshConfigAction::run($sshConfig, ['auth_type' => 'key', 'private_key' => 'KEY']);

    expect($sshConfig->fresh())->password->toBeNull()->private_key->toBe('KEY');
});

test('switching to password authentication drops the key and its passphrase', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create(['auth_type' => 'key', 'private_key' => 'KEY', 'key_passphrase' => 'phrase']);

    UpdateSshConfigAction::run($sshConfig, ['auth_type' => 'password', 'password' => 'secret']);

    expect($sshConfig->fresh())->private_key->toBeNull()->key_passphrase->toBeNull()->password->toBe('secret');
});

test('a new key without its own passphrase drops the old key\'s passphrase', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create(['auth_type' => 'key', 'private_key' => 'OLD', 'key_passphrase' => 'phrase']);

    UpdateSshConfigAction::run($sshConfig, ['auth_type' => 'key', 'private_key' => 'NEW', 'key_passphrase' => '']);

    expect($sshConfig->fresh())->private_key->toBe('NEW')->key_passphrase->toBeNull();
});

test('the port is kept when none is given', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create(['port' => 2222]);

    UpdateSshConfigAction::run($sshConfig, ['port' => null]);

    expect($sshConfig->fresh()->port)->toBe(2222);
});
