<?php

use App\Livewire\DatabaseServer\Create;
use App\Livewire\DatabaseServer\Edit;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('can create database server with SSH tunnel (password auth)', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSH Tunnel Server')
        ->set('form.database_type', 'mysql')
        ->set('form.host', 'private-db.internal')
        ->set('form.port', 3306)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.database_names_input', 'myapp_production')
        ->set('form.volume_id', $volume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->set('form.retention_days', 14)
        // SSH tunnel config
        ->set('form.ssh_enabled', true)
        ->set('form.ssh_config_mode', 'create')
        ->set('form.ssh_host', 'bastion.example.com')
        ->set('form.ssh_port', 22)
        ->set('form.ssh_username', 'tunnel_user')
        ->set('form.ssh_auth_type', 'password')
        ->set('form.ssh_password', 'ssh_secret')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    $this->assertDatabaseHas('database_servers', [
        'name' => 'SSH Tunnel Server',
        'database_type' => 'mysql',
    ]);

    $server = DatabaseServer::where('name', 'SSH Tunnel Server')->first();
    expect($server->ssh_config_id)->not->toBeNull();
    expect($server->sshConfig)->not->toBeNull();
    expect($server->sshConfig->host)->toBe('bastion.example.com');
    expect($server->sshConfig->port)->toBe(22);
    expect($server->sshConfig->username)->toBe('tunnel_user');
    expect($server->sshConfig->auth_type)->toBe('password');
    // Password should be stored (encrypted by model cast)
    expect($server->sshConfig->password)->toBe('ssh_secret');
});

test('can create database server with SSH tunnel (key auth)', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    $privateKey = "-----BEGIN OPENSSH PRIVATE KEY-----\ntest_key_content\n-----END OPENSSH PRIVATE KEY-----";

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSH Key Auth Server')
        ->set('form.database_type', 'postgres')
        ->set('form.host', 'private-postgres.internal')
        ->set('form.port', 5432)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.database_names_input', 'myapp_production')
        ->set('form.volume_id', $volume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->set('form.retention_days', 14)
        // SSH tunnel config
        ->set('form.ssh_enabled', true)
        ->set('form.ssh_config_mode', 'create')
        ->set('form.ssh_host', 'bastion.example.com')
        ->set('form.ssh_port', 2222)
        ->set('form.ssh_username', 'tunnel_user')
        ->set('form.ssh_auth_type', 'key')
        ->set('form.ssh_private_key', $privateKey)
        ->set('form.ssh_key_passphrase', 'keypass123')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    $server = DatabaseServer::where('name', 'SSH Key Auth Server')->first();
    expect($server->sshConfig->auth_type)->toBe('key');
    expect($server->sshConfig->port)->toBe(2222);
    // Private key and passphrase should be stored (encrypted by model cast)
    expect($server->sshConfig->private_key)->toBe($privateKey);
    expect($server->sshConfig->key_passphrase)->toBe('keypass123');
});

test('can create database server using existing SSH config', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Test Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    // Create existing SSH config
    $existingSshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'shared-bastion.example.com',
        'username' => 'shared_user',
    ]);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Server Using Existing SSH')
        ->set('form.database_type', 'mysql')
        ->set('form.host', 'private-db.internal')
        ->set('form.port', 3306)
        ->set('form.username', 'dbuser')
        ->set('form.password', 'secret123')
        ->set('form.database_names_input', 'myapp_production')
        ->set('form.volume_id', $volume->id)
        ->set('form.backup_schedule_id', dailySchedule()->id)
        ->set('form.retention_days', 14)
        // Use existing SSH config
        ->set('form.ssh_enabled', true)
        ->set('form.ssh_config_mode', 'existing')
        ->set('form.ssh_config_id', $existingSshConfig->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('database-servers.index'));

    $server = DatabaseServer::where('name', 'Server Using Existing SSH')->first();
    expect($server->ssh_config_id)->toBe($existingSshConfig->id);
    expect($server->sshConfig->host)->toBe('shared-bastion.example.com');
});

test('requiresSshTunnel returns correct value', function () {
    $serverWithTunnel = DatabaseServer::factory()->withSshTunnel()->create();
    $serverWithoutTunnel = DatabaseServer::factory()->create();

    expect($serverWithTunnel->requiresSshTunnel())->toBeTrue();
    expect($serverWithoutTunnel->requiresSshTunnel())->toBeFalse();
});

test('SSH config model provides accessor methods', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'bastion.example.com',
        'port' => 2222,
        'username' => 'myuser',
        'password' => 'test_password',
    ]);

    // getDecrypted returns decrypted values
    $decrypted = $sshConfig->getDecrypted();
    expect($decrypted['host'])->toBe('bastion.example.com')
        ->and($decrypted['password'])->toBe('test_password');

    // getSafe removes sensitive fields
    $safe = $sshConfig->getSafe();
    expect($safe)->toHaveKey('host')
        ->and($safe)->toHaveKey('username')
        ->and($safe)->not->toHaveKey('password')
        ->and($safe)->not->toHaveKey('private_key')
        ->and($safe)->not->toHaveKey('key_passphrase');

    // getDisplayName returns correct format
    expect($sshConfig->getDisplayName())->toBe('myuser@bastion.example.com:2222');
});

test('SSH tunnel section is not shown for SQLite', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.database_type', 'sqlite')
        ->assertDontSee('SSH Tunnel');
});

test('updating database server preserves SSH config when not changed', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withSshTunnel()->create([
        'database_names' => ['mydb'],
    ]);
    $originalSshConfigId = $server->ssh_config_id;
    $originalHost = $server->sshConfig->host;

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->set('form.name', 'Updated Name')
        // SSH fields left empty (should preserve existing)
        ->call('save')
        ->assertHasNoErrors();

    $server->refresh();
    expect($server->name)->toBe('Updated Name');
    // SSH config should be preserved
    expect($server->ssh_config_id)->toBe($originalSshConfigId);
    expect($server->sshConfig->host)->toBe($originalHost);
});

test('disabling SSH tunnel clears SSH config link', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withSshTunnel()->create([
        'database_names' => ['mydb'],
    ]);

    expect($server->requiresSshTunnel())->toBeTrue();

    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->set('form.ssh_enabled', false)
        ->call('save')
        ->assertHasNoErrors();

    $server->refresh();
    expect($server->ssh_config_id)->toBeNull();
    expect($server->requiresSshTunnel())->toBeFalse();
});

test('testSshConnection calls form method', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withSshTunnel()->create();

    // Calling testSshConnection should update form state
    // The actual test will fail (no real SSH server) but the code path is exercised
    Livewire::actingAs($user)
        ->test(Edit::class, ['server' => $server])
        ->set('form.ssh_enabled', true)
        ->set('form.ssh_host', 'nonexistent.invalid.host')
        ->set('form.ssh_port', 22)
        ->set('form.ssh_username', 'testuser')
        ->set('form.ssh_password', 'testpass')
        ->call('testSshConnection')
        ->assertSet('form.testingSshConnection', false)
        ->assertSet('form.sshTestSuccess', false);
});
