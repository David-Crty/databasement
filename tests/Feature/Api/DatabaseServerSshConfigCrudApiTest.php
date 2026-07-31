<?php

use App\Enums\Ability;
use App\Models\DatabaseServerSshConfig;
use App\Models\User;

// ─── Store ───────────────────────────────────────────────────────────────────

test('unauthenticated users cannot create ssh configs', function () {
    $this->postJson('/api/v1/database-server-ssh-configs')
        ->assertUnauthorized();
});

test('without manage-database-servers, creating an ssh config via api is forbidden', function () {
    $user = User::factory()->withAllAbilitiesExcept(Ability::ManageDatabaseServers->value)->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-server-ssh-configs', [
            'host' => 'bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'password',
            'password' => 'ssh_secret',
        ])
        ->assertForbidden();
});

test('manage-database-servers allows creating a password-based ssh config', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-server-ssh-configs', [
            'host' => 'bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'password',
            'password' => 'ssh_secret',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.host', 'bastion.example.com')
        ->assertJsonPath('data.auth_type', 'password')
        ->assertJsonPath('data.port', 22);

    expect($response->json('data'))->not->toHaveKeys(['password', 'private_key', 'key_passphrase']);

    /** @var DatabaseServerSshConfig $config */
    $config = DatabaseServerSshConfig::findOrFail($response->json('data.id'));
    expect($config->password)->toBe('ssh_secret');
});

test('the created id can be used as ssh_config_id on a database server', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();

    $configId = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-server-ssh-configs', [
            'host' => 'bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'password',
            'password' => 'ssh_secret',
        ])->json('data.id');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'Tunneled MySQL',
            'database_type' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'root',
            'password' => 'secret',
            'ssh_config_id' => $configId,
            'backups_enabled' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('data.ssh_config_id', $configId);
});

test('generate_key creates the keypair server-side and returns the public key once', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-server-ssh-configs', [
            'host' => 'bastion.example.com',
            'port' => 2222,
            'username' => 'tunnel_user',
            'auth_type' => 'key',
            'generate_key' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.port', 2222);

    expect($response->json('data.public_key'))->toStartWith('ssh-ed25519 ');

    /** @var DatabaseServerSshConfig $config */
    $config = DatabaseServerSshConfig::findOrFail($response->json('data.id'));
    expect($config->private_key)->toContain('OPENSSH PRIVATE KEY');

    // The public key is not stored, so it is never returned again.
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/database-server-ssh-configs/{$config->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.public_key');
});

test('key generation is rejected for password authentication', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-server-ssh-configs', [
            'host' => 'bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'password',
            'password' => 'ssh_secret',
            'generate_key' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['generate_key']);
});

test('key authentication requires a private key unless one is generated', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-server-ssh-configs', [
            'host' => 'bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'key',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['private_key']);
});

// ─── Index & show ────────────────────────────────────────────────────────────

test('viewing ssh configs needs no ability', function () {
    $user = User::factory()->withAbilities([])->create();
    $config = DatabaseServerSshConfig::factory()->create();

    $listed = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/database-server-ssh-configs')
        ->assertOk()
        ->assertJsonPath('data.0.id', $config->id);

    // Listed items carry no credentials and no public_key placeholder.
    expect($listed->json('data.0'))->not->toHaveKeys(['password', 'private_key', 'key_passphrase', 'public_key']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/database-server-ssh-configs/{$config->id}");

    $response->assertOk()->assertJsonPath('data.username', 'tunnel_user');
    expect($response->json('data'))->not->toHaveKeys(['password', 'private_key', 'key_passphrase']);
});

// ─── Update ──────────────────────────────────────────────────────────────────

test('without manage-database-servers, updating an ssh config via api is forbidden', function () {
    $user = User::factory()->withAllAbilitiesExcept(Ability::ManageDatabaseServers->value)->create();
    $config = DatabaseServerSshConfig::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-server-ssh-configs/{$config->id}", [
            'host' => 'new-bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'password',
        ])
        ->assertForbidden();
});

test('updating without credentials keeps the stored ones', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $config = DatabaseServerSshConfig::factory()->create(['port' => 2222]);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-server-ssh-configs/{$config->id}", [
            'host' => 'new-bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'password',
        ])
        ->assertOk()
        ->assertJsonPath('data.host', 'new-bastion.example.com')
        ->assertJsonPath('data.port', 2222);

    expect($config->fresh()->password)->toBe('ssh_password');
});

test('switching to key authentication requires a key', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $config = DatabaseServerSshConfig::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-server-ssh-configs/{$config->id}", [
            'host' => 'bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'key',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['private_key']);
});

test('switching auth type clears the credentials of the previous one', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $config = DatabaseServerSshConfig::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-server-ssh-configs/{$config->id}", [
            'host' => 'bastion.example.com',
            'username' => 'tunnel_user',
            'auth_type' => 'key',
            'generate_key' => true,
        ]);

    $response->assertOk()->assertJsonPath('data.auth_type', 'key');
    expect($response->json('data.public_key'))->toStartWith('ssh-ed25519 ');

    $config->refresh();
    expect($config->password)->toBeNull()
        ->and($config->private_key)->toContain('OPENSSH PRIVATE KEY');
});

// ─── Destroy ─────────────────────────────────────────────────────────────────

test('without manage-database-servers, deleting an ssh config via api is forbidden', function () {
    $user = User::factory()->withAllAbilitiesExcept(Ability::ManageDatabaseServers->value)->create();
    $config = DatabaseServerSshConfig::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/database-server-ssh-configs/{$config->id}")
        ->assertForbidden();
});

test('an ssh config still used by a database server cannot be deleted', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $config = DatabaseServerSshConfig::factory()->create();
    createDatabaseServer(['ssh_config_id' => $config->id]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/database-server-ssh-configs/{$config->id}")
        ->assertUnprocessable();

    $this->assertDatabaseHas('database_server_ssh_configs', ['id' => $config->id]);
});

test('an unused ssh config can be deleted', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $config = DatabaseServerSshConfig::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/database-server-ssh-configs/{$config->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('database_server_ssh_configs', ['id' => $config->id]);
});
