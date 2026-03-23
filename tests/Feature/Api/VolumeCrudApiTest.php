<?php

use App\Models\User;
use App\Models\Volume;

dataset('volume store payloads', [
    'local' => [[
        'endpoint' => '/api/v1/volumes/local',
        'payload' => [
            'name' => 'Local Backups',
            'config' => ['path' => '/backups'],
        ],
        'expect' => ['data.type' => 'local', 'data.config.path' => '/backups'],
        'hidden_config_keys' => [],
    ]],
    's3' => [[
        'endpoint' => '/api/v1/volumes/s3',
        'payload' => [
            'name' => 'S3 Backups',
            'config' => [
                'bucket' => 'my-backups',
                'region' => 'us-east-1',
                'access_key_id' => 'AKIATEST',
                'secret_access_key' => 'secret123',
            ],
        ],
        'expect' => ['data.type' => 's3', 'data.config.bucket' => 'my-backups'],
        'hidden_config_keys' => ['secret_access_key'],
    ]],
    'sftp' => [[
        'endpoint' => '/api/v1/volumes/sftp',
        'payload' => [
            'name' => 'SFTP Backups',
            'config' => [
                'host' => 'sftp.example.com',
                'port' => 22,
                'username' => 'backup',
                'password' => 'secret',
                'root' => '/backups',
            ],
        ],
        'expect' => ['data.type' => 'sftp', 'data.config.host' => 'sftp.example.com'],
        'hidden_config_keys' => ['password'],
    ]],
    'ftp' => [[
        'endpoint' => '/api/v1/volumes/ftp',
        'payload' => [
            'name' => 'FTP Backups',
            'config' => [
                'host' => 'ftp.example.com',
                'port' => 21,
                'username' => 'backup',
                'password' => 'secret',
                'root' => '/backups',
                'ssl' => true,
                'passive' => true,
            ],
        ],
        'expect' => ['data.type' => 'ftp', 'data.config.host' => 'ftp.example.com'],
        'hidden_config_keys' => ['password'],
    ]],
]);

// ─── Store ───────────────────────────────────────────────────────────────────

test('unauthenticated users cannot create volumes', function () {
    $this->postJson('/api/v1/volumes/local')
        ->assertUnauthorized();
});

test('viewers cannot create volumes', function () {
    $user = User::factory()->create(['role' => 'viewer']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes/local', [
            'name' => 'Test Volume',
            'config' => ['path' => '/backups'],
        ])
        ->assertForbidden();
});

test('can create volume and sensitive fields are hidden', function (array $data) {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson($data['endpoint'], $data['payload']);

    $response->assertCreated()
        ->assertJsonPath('data.name', $data['payload']['name']);

    foreach ($data['expect'] as $path => $value) {
        $response->assertJsonPath($path, $value);
    }

    foreach ($data['hidden_config_keys'] as $key) {
        expect($response->json('data.config'))->not->toHaveKey($key);
    }

    $this->assertDatabaseHas('volumes', ['name' => $data['payload']['name']]);
})->with('volume store payloads');

test('store returns validation errors for missing required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes/s3', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'config']);
});

test('store validates type-specific config', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/volumes/s3', [
            'name' => 'Bad S3',
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
            'config' => [
                'host' => 'sftp.example.com',
                'username' => 'backup',
                'password' => '',
                'root' => '/backups',
            ],
        ])
        ->assertOk();

    $volume->refresh();
    expect($volume->config['password'])->not->toBeEmpty();
});

test('update returns validation errors', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->local()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/volumes/{$volume->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
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
