<?php

use App\Enums\Ability;
use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// ─── Index ───────────────────────────────────────────────────────────────────

test('unauthenticated users cannot list scheduled restores', function () {
    $this->getJson('/api/v1/scheduled-restores')->assertUnauthorized();
});

test('can list scheduled restores via api', function () {
    // Viewing needs no ability — any org member can read scheduled restores.
    $user = User::factory()->withAbilities([])->create();
    [$source, $target] = createRestoreServerPair();

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
    // Viewing needs no ability — any org member can read a scheduled restore.
    $user = User::factory()->withAbilities([])->create();
    [$source, $target] = createRestoreServerPair();
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
    // operate-restores alone is sufficient to schedule a restore.
    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    [$source, $target] = createRestoreServerPair();
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

test('store rejects mismatched server types', function () {
    $user = User::factory()->create();
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['app']]);
    $target = DatabaseServer::factory()->create(['database_type' => 'postgres', 'database_names' => ['target']]);
    $schedule = dailySchedule();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/scheduled-restores', [
            'name' => 'Mismatched',
            'source_server_id' => $source->id,
            'source_database_name' => 'app',
            'target_server_id' => $target->id,
            'schema_name' => 'restored_db',
            'backup_schedule_id' => $schedule->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['target_server_id']);
});

test('without operate-restores, creating a scheduled restore via api is forbidden', function () {
    // Necessity proof: holding every ability except operate-restores must still be forbidden.
    $user = User::factory()->withAllAbilitiesExcept(Ability::OperateRestores->value)->create();
    [$source, $target] = createRestoreServerPair();
    $schedule = dailySchedule();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/scheduled-restores', [
            'name' => 'Nightly',
            'source_server_id' => $source->id,
            'source_database_name' => 'app',
            'target_server_id' => $target->id,
            'schema_name' => 'restored_db',
            'backup_schedule_id' => $schedule->id,
        ])
        ->assertForbidden();
});

// ─── Update ──────────────────────────────────────────────────────────────────

test('can update a scheduled restore via api', function () {
    // operate-restores alone is sufficient to update a scheduled restore.
    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    [$source, $target] = createRestoreServerPair();
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
            'source_database_name' => 'app',
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
    // operate-restores alone is sufficient to delete a scheduled restore.
    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    [$source, $target] = createRestoreServerPair();

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

    // operate-restores alone is sufficient to run a scheduled restore.
    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    [$source, $target] = createRestoreServerPair();

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

test('without operate-restores, triggering a scheduled restore via api is forbidden', function () {
    // Necessity proof: holding every ability except operate-restores must still be forbidden.
    $user = User::factory()->withAllAbilitiesExcept(Ability::OperateRestores->value)->create();
    [$source, $target] = createRestoreServerPair();

    $scheduled = ScheduledRestore::factory()->create([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/scheduled-restores/{$scheduled->id}/run")
        ->assertForbidden();
});
