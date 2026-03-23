<?php

use App\Models\BackupSchedule;
use App\Models\User;

// ─── Index ───────────────────────────────────────────────────────────────────

test('unauthenticated users cannot list backup schedules', function () {
    $this->getJson('/api/v1/backup-schedules')
        ->assertUnauthorized();
});

test('can list backup schedules via api', function () {
    $user = User::factory()->create();
    BackupSchedule::factory()->create(['name' => 'Schedule Alpha']);
    BackupSchedule::factory()->create(['name' => 'Schedule Beta']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/backup-schedules');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'expression']]]);

    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('Schedule Alpha', 'Schedule Beta');
});

// ─── Show ────────────────────────────────────────────────────────────────────

test('unauthenticated users cannot view a backup schedule', function () {
    $schedule = BackupSchedule::factory()->create();

    $this->getJson("/api/v1/backup-schedules/{$schedule->id}")
        ->assertUnauthorized();
});

test('can view a backup schedule via api', function () {
    $user = User::factory()->create();
    $schedule = BackupSchedule::factory()->create(['name' => 'Nightly', 'expression' => '0 2 * * *']);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/backup-schedules/{$schedule->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $schedule->id)
        ->assertJsonPath('data.name', 'Nightly')
        ->assertJsonPath('data.expression', '0 2 * * *');
});

// ─── Store ───────────────────────────────────────────────────────────────────

test('unauthenticated users cannot create backup schedules', function () {
    $this->postJson('/api/v1/backup-schedules')
        ->assertUnauthorized();
});

test('viewers cannot create backup schedules', function () {
    $user = User::factory()->create(['role' => 'viewer']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/backup-schedules', [
            'name' => 'Nightly',
            'expression' => '0 2 * * *',
        ])
        ->assertForbidden();
});

test('can create a backup schedule via api', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/backup-schedules', [
            'name' => 'Nightly',
            'expression' => '0 2 * * *',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Nightly')
        ->assertJsonPath('data.expression', '0 2 * * *');

    $this->assertDatabaseHas('backup_schedules', ['name' => 'Nightly']);
});

test('store returns validation errors for missing required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/backup-schedules', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'expression']);
});

test('store rejects invalid cron expression', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/backup-schedules', [
            'name' => 'Bad Schedule',
            'expression' => 'not-a-cron',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expression']);
});

test('store rejects duplicate name', function () {
    $user = User::factory()->create();
    BackupSchedule::factory()->create(['name' => 'Hourly']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/backup-schedules', [
            'name' => 'Hourly',
            'expression' => '0 * * * *',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

// ─── Update ──────────────────────────────────────────────────────────────────

test('unauthenticated users cannot update backup schedules', function () {
    $schedule = BackupSchedule::factory()->create();

    $this->putJson("/api/v1/backup-schedules/{$schedule->id}")
        ->assertUnauthorized();
});

test('viewers cannot update backup schedules', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    $schedule = BackupSchedule::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/backup-schedules/{$schedule->id}", [
            'name' => 'Updated',
            'expression' => '0 3 * * *',
        ])
        ->assertForbidden();
});

test('can update a backup schedule via api', function () {
    $user = User::factory()->create();
    $schedule = BackupSchedule::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/backup-schedules/{$schedule->id}", [
            'name' => 'Updated Schedule',
            'expression' => '30 4 * * 0',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Schedule')
        ->assertJsonPath('data.expression', '30 4 * * 0');
});

test('update allows keeping the same name', function () {
    $user = User::factory()->create();
    $schedule = BackupSchedule::factory()->create(['name' => 'Hourly']);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/backup-schedules/{$schedule->id}", [
            'name' => 'Hourly',
            'expression' => '0 5 * * *',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Hourly');
});

test('update returns validation errors', function () {
    $user = User::factory()->create();
    $schedule = BackupSchedule::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/backup-schedules/{$schedule->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'expression']);
});

// ─── Destroy ─────────────────────────────────────────────────────────────────

test('unauthenticated users cannot delete backup schedules', function () {
    $schedule = BackupSchedule::factory()->create();

    $this->deleteJson("/api/v1/backup-schedules/{$schedule->id}")
        ->assertUnauthorized();
});

test('viewers cannot delete backup schedules', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    $schedule = BackupSchedule::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/backup-schedules/{$schedule->id}")
        ->assertForbidden();
});

test('can delete a backup schedule via api', function () {
    $user = User::factory()->create();
    $schedule = BackupSchedule::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/backup-schedules/{$schedule->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('backup_schedules', ['id' => $schedule->id]);
});
