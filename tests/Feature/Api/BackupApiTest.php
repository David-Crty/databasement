<?php

use App\Enums\Ability;
use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('unauthenticated users cannot trigger a backup', function () {
    $server = DatabaseServer::factory()->create();

    $response = $this->postJson("/api/v1/database-servers/{$server->id}/backup");

    $response->assertUnauthorized();
});

test('without run-backups, triggering a backup via api is forbidden', function () {
    // Necessity proof: holding every ability except run-backups must still be forbidden.
    $user = User::factory()->withAllAbilitiesExcept(Ability::RunBackups->value)->create();
    $server = DatabaseServer::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/backup");

    $response->assertForbidden();
});

test('authenticated users can trigger a backup', function () {
    Queue::fake();

    // run-backups alone is sufficient to trigger a backup.
    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
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
