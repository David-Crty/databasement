<?php

use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('unauthenticated users cannot trigger a backup', function () {
    $server = DatabaseServer::factory()->create();

    $response = $this->postJson("/api/v1/database-servers/{$server->id}/backup");

    $response->assertUnauthorized();
});

test('viewer users cannot trigger a backup', function () {
    $user = User::factory()->create(['role' => User::ROLE_VIEWER]);
    $server = DatabaseServer::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/backup");

    $response->assertForbidden();
});

test('authenticated users can trigger a backup', function () {
    Queue::fake();

    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
        'database_selection_mode' => 'selected',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/backup");

    $response->assertStatus(202)
        ->assertJsonPath('message', 'Backup started successfully!')
        ->assertJsonStructure([
            'message',
            'snapshots' => [
                '*' => ['id', 'database_name', 'database_type'],
            ],
        ]);

    Queue::assertPushed(ProcessBackupJob::class);
});
