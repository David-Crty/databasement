<?php

use App\Models\User;
use App\Models\Volume;

test('unauthenticated users cannot access volumes api', function () {
    $this->getJson('/api/v1/volumes')->assertUnauthorized();
});

test('authenticated users can list volumes via api', function () {
    $user = User::factory()->create();
    Volume::factory()->count(3)->create();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/volumes');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'type', 'created_at', 'updated_at'],
            ],
            'links',
            'meta',
        ]);
});

test('authenticated users can filter volumes by name', function () {
    $user = User::factory()->create();
    Volume::factory()->create(['name' => 'Production Backups']);
    Volume::factory()->create(['name' => 'Development Storage']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/volumes?filter[name]=Production');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Production Backups');
});

test('authenticated users can filter volumes by type', function () {
    $user = User::factory()->create();
    Volume::factory()->create(['type' => 'local']);
    Volume::factory()->create(['type' => 's3']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/volumes?filter[type]=s3');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 's3');
});

test('authenticated users can sort volumes', function () {
    $user = User::factory()->create();
    Volume::factory()->create(['name' => 'Zebra Volume']);
    Volume::factory()->create(['name' => 'Alpha Volume']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/volumes?sort=name');

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha Volume')
        ->assertJsonPath('data.1.name', 'Zebra Volume');
});

test('authenticated users can get a specific volume', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->create(['name' => 'Test Volume']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/volumes/{$volume->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $volume->id)
        ->assertJsonPath('data.name', 'Test Volume');
});
