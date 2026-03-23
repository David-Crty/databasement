<?php

use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\Databases\DatabaseProvider;

// ─── Store ───────────────────────────────────────────────────────────────────

test('unauthenticated users cannot create database servers', function () {
    $this->postJson('/api/v1/database-servers')
        ->assertUnauthorized();
});

test('viewers cannot create database servers', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    $volume = Volume::factory()->local()->create();
    $schedule = BackupSchedule::firstOrCreate(['name' => 'Daily'], ['expression' => '0 2 * * *']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'Test',
            'database_type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'database_selection_mode' => 'all',
            'backup' => [
                'volume_id' => $volume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_policy' => 'days',
                'retention_days' => 14,
            ],
        ])
        ->assertForbidden();
});

test('can create a mysql database server via api', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->local()->create();
    $schedule = BackupSchedule::firstOrCreate(['name' => 'Daily'], ['expression' => '0 2 * * *']);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'Production MySQL',
            'database_type' => 'mysql',
            'host' => 'db.example.com',
            'port' => 3306,
            'username' => 'root',
            'password' => 'secret',
            'database_selection_mode' => 'all',
            'description' => 'Production database',
            'managed_by' => 'docker:abc123',
            'backup' => [
                'volume_id' => $volume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_policy' => 'days',
                'retention_days' => 14,
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Production MySQL')
        ->assertJsonPath('data.host', 'db.example.com')
        ->assertJsonPath('data.port', 3306)
        ->assertJsonPath('data.database_type', 'mysql')
        ->assertJsonPath('data.managed_by', 'docker:abc123')
        ->assertJsonPath('data.backup.volume_id', $volume->id)
        ->assertJsonPath('data.backup.retention_policy', 'days')
        ->assertJsonPath('data.backup.retention_days', 14);

    $this->assertDatabaseHas('database_servers', [
        'name' => 'Production MySQL',
        'managed_by' => 'docker:abc123',
    ]);
});

test('can create a server with backups disabled', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'No Backups Server',
            'database_type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'database_selection_mode' => 'all',
            'backups_enabled' => false,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.backups_enabled', false);

    $server = DatabaseServer::where('name', 'No Backups Server')->first();
    expect($server->backup)->toBeNull();
});

test('store normalizes redis selection mode to all', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'Redis Server',
            'database_type' => 'redis',
            'host' => 'localhost',
            'port' => 6379,
            'backups_enabled' => false,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.database_selection_mode', 'all')
        ->assertJsonPath('data.database_names', null);
});

test('store normalizes sqlite selection mode to selected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'SQLite DB',
            'database_type' => 'sqlite',
            'database_names' => ['/data/app.sqlite'],
            'backups_enabled' => false,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.database_selection_mode', 'selected')
        ->assertJsonPath('data.database_names', ['/data/app.sqlite']);
});

test('store moves auth_source and dump_flags to extra_config', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'MySQL with flags',
            'database_type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'database_selection_mode' => 'all',
            'dump_flags' => '--single-transaction',
            'backups_enabled' => false,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.extra_config.dump_flags', '--single-transaction');
});

test('update preserves extra_config when keys are not sent', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'extra_config' => ['dump_flags' => '--single-transaction'],
    ]);
    $volume = Volume::factory()->local()->create();
    $schedule = BackupSchedule::firstOrCreate(['name' => 'Daily'], ['expression' => '0 2 * * *']);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-servers/{$server->id}", [
            'name' => 'Updated Name',
            'database_type' => 'mysql',
            'host' => $server->host,
            'port' => $server->port,
            'username' => $server->username,
            'database_selection_mode' => 'all',
            'backup' => [
                'volume_id' => $volume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_policy' => 'days',
                'retention_days' => 14,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.extra_config.dump_flags', '--single-transaction');
});

test('password is not in store response', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'Secret Server',
            'database_type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'password' => 'super-secret',
            'database_selection_mode' => 'all',
            'backups_enabled' => false,
        ]);

    $response->assertCreated()
        ->assertJsonMissing(['password' => 'super-secret']);
});

test('store returns validation errors for missing required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'database_type']);
});

test('can create a server with backup config including gfs retention', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->local()->create();
    $schedule = BackupSchedule::firstOrCreate(['name' => 'Daily'], ['expression' => '0 2 * * *']);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'GFS Server',
            'database_type' => 'postgres',
            'host' => 'localhost',
            'port' => 5432,
            'username' => 'postgres',
            'database_selection_mode' => 'all',
            'backup' => [
                'volume_id' => $volume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_policy' => 'gfs',
                'gfs_keep_daily' => 7,
                'gfs_keep_weekly' => 4,
                'gfs_keep_monthly' => 12,
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.backup.retention_policy', 'gfs')
        ->assertJsonPath('data.backup.gfs_keep_daily', 7)
        ->assertJsonPath('data.backup.gfs_keep_weekly', 4)
        ->assertJsonPath('data.backup.gfs_keep_monthly', 12)
        ->assertJsonPath('data.backup.retention_days', null);
});

// ─── Update ──────────────────────────────────────────────────────────────────

test('unauthenticated users cannot update database servers', function () {
    $server = DatabaseServer::factory()->create();

    $this->putJson("/api/v1/database-servers/{$server->id}")
        ->assertUnauthorized();
});

test('viewers cannot update database servers', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $volume = Volume::factory()->local()->create();
    $schedule = BackupSchedule::firstOrCreate(['name' => 'Daily'], ['expression' => '0 2 * * *']);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-servers/{$server->id}", [
            'name' => 'Updated',
            'database_type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'database_selection_mode' => 'all',
            'backup' => [
                'volume_id' => $volume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_policy' => 'days',
                'retention_days' => 14,
            ],
        ])
        ->assertForbidden();
});

test('can update a database server via api', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $volume = Volume::factory()->local()->create();
    $schedule = BackupSchedule::firstOrCreate(['name' => 'Daily'], ['expression' => '0 2 * * *']);

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-servers/{$server->id}", [
            'name' => 'Updated Server',
            'database_type' => 'mysql',
            'host' => 'new-host.example.com',
            'port' => 3307,
            'username' => 'admin',
            'database_selection_mode' => 'all',
            'managed_by' => 'k8s:default/mysql-pod',
            'backup' => [
                'volume_id' => $volume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_policy' => 'days',
                'retention_days' => 30,
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Server')
        ->assertJsonPath('data.host', 'new-host.example.com')
        ->assertJsonPath('data.managed_by', 'k8s:default/mysql-pod');
});

test('blank password keeps existing password on update', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'password' => 'original-password',
    ]);
    $volume = Volume::factory()->local()->create();
    $schedule = BackupSchedule::firstOrCreate(['name' => 'Daily'], ['expression' => '0 2 * * *']);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-servers/{$server->id}", [
            'name' => $server->name,
            'database_type' => 'mysql',
            'host' => $server->host,
            'port' => $server->port,
            'username' => $server->username,
            'password' => '',
            'database_selection_mode' => 'all',
            'backup' => [
                'volume_id' => $volume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_policy' => 'days',
                'retention_days' => 14,
            ],
        ]);

    $server->refresh();
    expect($server->getDecryptedPassword())->toBe('original-password');
});

test('update syncs backup configuration', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['database_type' => 'postgres']);
    $newVolume = Volume::factory()->local()->create();
    $schedule = BackupSchedule::firstOrCreate(['name' => 'Daily'], ['expression' => '0 2 * * *']);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-servers/{$server->id}", [
            'name' => $server->name,
            'database_type' => 'postgres',
            'host' => $server->host,
            'port' => $server->port,
            'username' => $server->username,
            'database_selection_mode' => 'all',
            'backup' => [
                'volume_id' => $newVolume->id,
                'backup_schedule_id' => $schedule->id,
                'retention_policy' => 'forever',
            ],
        ]);

    $server->refresh();
    expect($server->backup->volume_id)->toBe($newVolume->id)
        ->and($server->backup->retention_policy)->toBe('forever');
});

test('update returns validation errors', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/database-servers/{$server->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'database_type']);
});

// ─── Destroy ─────────────────────────────────────────────────────────────────

test('unauthenticated users cannot delete database servers', function () {
    $server = DatabaseServer::factory()->create();

    $this->deleteJson("/api/v1/database-servers/{$server->id}")
        ->assertUnauthorized();
});

test('viewers cannot delete database servers', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/database-servers/{$server->id}")
        ->assertForbidden();
});

test('can delete a database server via api', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/database-servers/{$server->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('database_servers', ['id' => $server->id]);
});

// ─── Filter by managed_by ───────────────────────────────────────────────────

test('can filter database servers by managed_by', function () {
    $user = User::factory()->create();
    DatabaseServer::factory()->create(['managed_by' => 'docker:abc123']);
    DatabaseServer::factory()->create(['managed_by' => 'k8s:default/mysql']);
    DatabaseServer::factory()->create(['managed_by' => null]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/database-servers?filter[managed_by]=docker:abc123');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.managed_by', 'docker:abc123');
});

// ─── Test Connection ────────────────────────────────────────────────────────

test('unauthenticated users cannot test connection', function () {
    $server = DatabaseServer::factory()->create();

    $this->getJson("/api/v1/database-servers/{$server->id}/test-connection")
        ->assertUnauthorized();
});

test('can test connection for a database server', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);

    $this->mock(DatabaseProvider::class, function ($mock) use ($server) {
        $mock->shouldReceive('testConnectionForServer')
            ->once()
            ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
            ->andReturn([
                'success' => true,
                'message' => 'Connection successful',
                'details' => ['ping_ms' => 12],
            ]);
    });

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/database-servers/{$server->id}/test-connection");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Connection successful')
        ->assertJsonPath('details.ping_ms', 12);
});

test('test connection returns failure details', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);

    $this->mock(DatabaseProvider::class, function ($mock) use ($server) {
        $mock->shouldReceive('testConnectionForServer')
            ->once()
            ->with(\Mockery::on(fn ($s) => $s->id === $server->id))
            ->andReturn([
                'success' => false,
                'message' => 'Connection refused',
                'details' => [],
            ]);
    });

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/database-servers/{$server->id}/test-connection");

    $response->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Connection refused');
});
