<?php

use App\Actions\DatabaseServer\CreateSshConfigAction;
use App\Models\Organization;

test('it creates the configuration in the organization, on port 22 without compression by default', function () {
    $organization = Organization::factory()->create();

    $sshConfig = CreateSshConfigAction::run($organization, [
        'host' => 'bastion.example.com',
        'port' => null,
        'username' => 'tunnel',
        'auth_type' => 'password',
        'password' => 'secret',
    ]);

    expect($sshConfig->fresh())
        ->organization_id->toBe($organization->id)
        ->port->toBe(22)
        ->compression->toBeFalse()
        ->password->toBe('secret');
});

test('it keeps only the credentials of its authentication type', function () {
    $sshConfig = CreateSshConfigAction::run(Organization::factory()->create(), [
        'host' => 'bastion.example.com',
        'username' => 'tunnel',
        'auth_type' => 'key',
        'password' => 'leftover',
        'private_key' => 'KEY',
    ]);

    expect($sshConfig->fresh())->password->toBeNull()->private_key->toBe('KEY');
});
