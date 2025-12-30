<?php

use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;

test('unauthenticated users cannot access jobs api', function () {
    $this->getJson('/api/v1/jobs')->assertUnauthorized();
});

test('authenticated users can list jobs via api', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory->createSnapshots($server, 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/jobs');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'type',
                    'status',
                    'error_message',
                    'started_at',
                    'completed_at',
                    'created_at',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('authenticated users can filter jobs by status', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);

    $completedSnapshots = $factory->createSnapshots($server, 'manual');
    $completedSnapshots[0]->job->update(['status' => 'completed']);

    $failedSnapshots = $factory->createSnapshots($server, 'manual');
    $failedSnapshots[0]->job->update(['status' => 'failed']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/jobs?filter[status]=completed');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'completed');
});

test('authenticated users can filter jobs by type', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshots = $factory->createSnapshots($server, 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/jobs?filter[type]=backup');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'backup');
});

test('authenticated users can sort jobs', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);

    $snapshot1 = $factory->createSnapshots($server, 'manual')[0];
    $snapshot1->job->update(['status' => 'completed', 'created_at' => now()->subDay()]);

    $snapshot2 = $factory->createSnapshots($server, 'manual')[0];
    $snapshot2->job->update(['status' => 'pending', 'created_at' => now()]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/jobs?sort=created_at');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $snapshot1->job->id);
});

test('authenticated users can get a specific job', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshots = $factory->createSnapshots($server, 'manual');
    $job = $snapshots[0]->job;

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/jobs/{$job->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $job->id)
        ->assertJsonPath('data.type', 'backup');
});
