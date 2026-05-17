<?php

use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Models\User;
use App\Services\SchedulerRestarter;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;

beforeEach(function () {
    $this->mock(SchedulerRestarter::class, function (MockInterface $mock) {
        $mock->shouldReceive('restart')->andReturn(true);
    });
});

function apiSourceTarget(string $type = 'mysql'): array
{
    $source = DatabaseServer::factory()->create(['database_type' => $type, 'database_names' => ['app']]);
    $target = DatabaseServer::factory()->create(['database_type' => $type, 'database_names' => ['target']]);

    return [$source, $target];
}

// ─── Index ───────────────────────────────────────────────────────────────────

test('unauthenticated users cannot list scheduled restores', function () {
    $this->getJson('/api/v1/scheduled-restores')->assertUnauthorized();
});

test('can list scheduled restores via api', function () {
    $user = User::factory()->create();
    [$source, $target] = apiSourceTarget();

    ScheduledRestore::factory()->create([
        'name' => 'Nightly staging refresh',
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/scheduled-restores');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'source_server_id', 'target_server_id', 'backup_schedule_id']]]);

    expect(collect($response->json('data'))->pluck('name'))->toContain('Nightly staging refresh');
});

// ─── Show ────────────────────────────────────────────────────────────────────

test('can view a scheduled restore via api', function () {
    $user = User::factory()->create();
    [$source, $target] = apiSourceTarget();
    $schedule = dailySchedule();

    $scheduled = ScheduledRestore::factory()->create([
        'name' => 'Refresh',
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
        'backup_schedule_id' => $schedule->id,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/scheduled-restores/{$scheduled->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Refresh')
        ->assertJsonPath('data.backup_schedule_id', $schedule->id);
});

// ─── Store ───────────────────────────────────────────────────────────────────

test('can create a scheduled restore via api', function () {
    $user = User::factory()->create();
    [$source, $target] = apiSourceTarget();
    $schedule = dailySchedule();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/scheduled-restores', [
            'name' => 'Nightly',
            'source_server_id' => $source->id,
            'source_database_name' => 'app',
            'target_server_id' => $target->id,
            'schema_name' => 'restored_db',
            'backup_schedule_id' => $schedule->id,
            'options' => ['force_database' => true],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Nightly')
        ->assertJsonPath('data.backup_schedule_id', $schedule->id);

    $this->assertDatabaseHas('scheduled_restores', [
        'name' => 'Nightly',
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
    ]);
});

test('store returns validation errors for missing required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/scheduled-restores', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'source_server_id', 'target_server_id', 'schema_name', 'backup_schedule_id']);
});

test('store rejects invalid backup_schedule_id', function () {
    $user = User::factory()->create();
    [$source, $target] = apiSourceTarget();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/scheduled-restores', [
            'name' => 'Bad',
            'source_server_id' => $source->id,
            'target_server_id' => $target->id,
            'schema_name' => 'restored_db',
            'backup_schedule_id' => 'non-existent-id',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['backup_schedule_id']);
});

test('store rejects mismatched server types', function () {
    $user = User::factory()->create();
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['app']]);
    $target = DatabaseServer::factory()->create(['database_type' => 'postgres', 'database_names' => ['target']]);
    $schedule = dailySchedule();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/scheduled-restores', [
            'name' => 'Mismatched',
            'source_server_id' => $source->id,
            'target_server_id' => $target->id,
            'schema_name' => 'restored_db',
            'backup_schedule_id' => $schedule->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['target_server_id']);
});

test('viewers cannot create scheduled restores', function () {
    $user = User::factory()->viewer()->create();
    [$source, $target] = apiSourceTarget();
    $schedule = dailySchedule();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/scheduled-restores', [
            'name' => 'Nightly',
            'source_server_id' => $source->id,
            'target_server_id' => $target->id,
            'schema_name' => 'restored_db',
            'backup_schedule_id' => $schedule->id,
        ])
        ->assertForbidden();
});

// ─── Update ──────────────────────────────────────────────────────────────────

test('can update a scheduled restore via api', function () {
    $user = User::factory()->create();
    [$source, $target] = apiSourceTarget();
    $schedule1 = dailySchedule();
    $schedule2 = weeklySchedule();

    $scheduled = ScheduledRestore::factory()->create([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
        'name' => 'Old name',
        'backup_schedule_id' => $schedule1->id,
    ]);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/scheduled-restores/{$scheduled->id}", [
            'name' => 'New name',
            'source_server_id' => $source->id,
            'target_server_id' => $target->id,
            'schema_name' => 'restored_db',
            'backup_schedule_id' => $schedule2->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New name')
        ->assertJsonPath('data.backup_schedule_id', $schedule2->id);
});

// ─── Destroy ─────────────────────────────────────────────────────────────────

test('can delete a scheduled restore via api', function () {
    $user = User::factory()->create();
    [$source, $target] = apiSourceTarget();

    $scheduled = ScheduledRestore::factory()->create([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
    ]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/scheduled-restores/{$scheduled->id}")
        ->assertNoContent();

    expect(ScheduledRestore::find($scheduled->id))->toBeNull();
});

// ─── Run ─────────────────────────────────────────────────────────────────────

test('can trigger a scheduled restore run via api', function () {
    Artisan::spy();

    $user = User::factory()->create();
    [$source, $target] = apiSourceTarget();

    $scheduled = ScheduledRestore::factory()->create([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/scheduled-restores/{$scheduled->id}/run")
        ->assertAccepted();

    Artisan::shouldHaveReceived('call')
        ->with('restores:run', ['scheduledRestore' => $scheduled->id])
        ->once();
});

test('viewers cannot trigger a scheduled restore', function () {
    $user = User::factory()->viewer()->create();
    [$source, $target] = apiSourceTarget();

    $scheduled = ScheduledRestore::factory()->create([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/scheduled-restores/{$scheduled->id}/run")
        ->assertForbidden();
});
