<?php

use App\Models\User;
use App\Models\Volume;

// ─── Store ───────────────────────────────────────────────────────────────────

test('unauthenticated users cannot create volumes', function () {
    $this->postJson('/api/v1/volumes')
        ->assertUnauthorized();
});

test('viewers cannot create volumes', function () {
    $user = User::factory()->create(['role' => 'viewer']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes', [
            'name' => 'Test Volume',
            'type' => 'local',
            'config' => ['path' => '/backups'],
        ])
        ->assertForbidden();
});

test('can create a local volume via api', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes', [
            'name' => 'Local Backups',
            'type' => 'local',
            'config' => ['path' => '/backups'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Local Backups')
        ->assertJsonPath('data.type', 'local')
        ->assertJsonPath('data.config.path', '/backups');

    $this->assertDatabaseHas('volumes', ['name' => 'Local Backups']);
});

test('can create an s3 volume via api', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes', [
            'name' => 'S3 Backups',
            'type' => 's3',
            'config' => [
                'bucket' => 'my-backups',
                'region' => 'us-east-1',
                'access_key_id' => 'AKIATEST',
                'secret_access_key' => 'secret123',
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'S3 Backups')
        ->assertJsonPath('data.type', 's3')
        ->assertJsonPath('data.config.bucket', 'my-backups');
});

test('sensitive fields are not exposed in store response', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes', [
            'name' => 'S3 Secret Test',
            'type' => 's3',
            'config' => [
                'bucket' => 'my-backups',
                'region' => 'us-east-1',
                'access_key_id' => 'AKIATEST',
                'secret_access_key' => 'super-secret-key',
            ],
        ]);

    $response->assertCreated();
    expect($response->json('data.config'))->not->toHaveKey('secret_access_key');
});

test('can create an sftp volume via api', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes', [
            'name' => 'SFTP Backups',
            'type' => 'sftp',
            'config' => [
                'host' => 'sftp.example.com',
                'port' => 22,
                'username' => 'backup',
                'password' => 'secret',
                'root' => '/backups',
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'sftp')
        ->assertJsonPath('data.config.host', 'sftp.example.com');

    // Password should not be in response
    expect($response->json('data.config'))->not->toHaveKey('password');
});

test('store returns validation errors for missing required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type']);
});

test('store validates type-specific config', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes', [
            'name' => 'Bad S3',
            'type' => 's3',
            'config' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['config.bucket', 'config.region']);
});

// ─── Update ──────────────────────────────────────────────────────────────────

test('unauthenticated users cannot update volumes', function () {
    $volume = Volume::factory()->create();

    $this->putJson("/api/v1/volumes/{$volume->id}")
        ->assertUnauthorized();
});

test('viewers cannot update volumes', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    $volume = Volume::factory()->local()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/volumes/{$volume->id}", [
            'name' => 'Updated',
            'type' => 'local',
            'config' => ['path' => '/backups'],
        ])
        ->assertForbidden();
});

test('can update a volume via api', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->local()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/volumes/{$volume->id}", [
            'name' => 'Updated Volume',
            'type' => 'local',
            'config' => ['path' => '/new-backups'],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Volume')
        ->assertJsonPath('data.config.path', '/new-backups');
});

test('blank sensitive fields preserve existing values on update', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->sftp()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/volumes/{$volume->id}", [
            'name' => $volume->name,
            'type' => 'sftp',
            'config' => [
                'host' => 'sftp.example.com',
                'username' => 'backup',
                'password' => '',
                'root' => '/backups',
            ],
        ])
        ->assertOk();

    $volume->refresh();
    // Password should still be present (preserved from existing)
    expect($volume->config['password'])->not->toBeEmpty();
});

test('update returns validation errors', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/volumes/{$volume->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type']);
});

// ─── Destroy ─────────────────────────────────────────────────────────────────

test('unauthenticated users cannot delete volumes', function () {
    $volume = Volume::factory()->create();

    $this->deleteJson("/api/v1/volumes/{$volume->id}")
        ->assertUnauthorized();
});

test('viewers cannot delete volumes', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    $volume = Volume::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/volumes/{$volume->id}")
        ->assertForbidden();
});

test('can delete a volume via api', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/volumes/{$volume->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('volumes', ['id' => $volume->id]);
});
