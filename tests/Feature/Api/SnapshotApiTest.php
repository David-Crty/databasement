<?php

use App\Enums\Ability;
use App\Enums\BackupJobStatus;
use App\Jobs\DeleteSnapshotsJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Illuminate\Support\Facades\Queue;

test('unauthenticated users cannot access snapshots api', function () {
    $this->getJson('/api/v1/snapshots')->assertUnauthorized();
});

test('authenticated users can list snapshots via api', function () {
    // Viewing needs no ability — any org member can read the snapshots API.
    $user = User::factory()->withAbilities([])->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory->createSnapshots($server->backups->first(), 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/snapshots');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'database_name',
                    'database_type',
                    'method',
                    'filename',
                    'file_size',
                    'created_at',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('authenticated users can filter snapshots by database name', function () {
    $user = User::factory()->withAbilities([])->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['database_names' => ['production_db']]);
    $factory->createSnapshots($server1->backups->first(), 'manual');

    $server2 = DatabaseServer::factory()->create(['database_names' => ['staging_db']]);
    $factory->createSnapshots($server2->backups->first(), 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/snapshots?filter[database_name]=production');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.database_name', 'production_db');
});

test('authenticated users can filter snapshots by database server id', function () {
    $user = User::factory()->withAbilities([])->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['database_names' => ['db_one']]);
    $factory->createSnapshots($server1->backups->first(), 'manual');

    $server2 = DatabaseServer::factory()->create(['database_names' => ['db_two']]);
    $factory->createSnapshots($server2->backups->first(), 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/snapshots?filter[database_server_id]={$server1->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.server.id', $server1->id);
});

test('authenticated users can filter snapshots by database type', function () {
    $user = User::factory()->withAbilities([])->create();
    $factory = app(BackupJobFactory::class);

    $mysqlServer = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'database_names' => ['mysql_db'],
    ]);
    $factory->createSnapshots($mysqlServer->backups->first(), 'manual');

    $pgServer = DatabaseServer::factory()->create([
        'database_type' => 'postgres',
        'database_names' => ['pg_db'],
    ]);
    $factory->createSnapshots($pgServer->backups->first(), 'manual');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/snapshots?filter[database_type]=mysql');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.database_type', 'mysql');
});

test('authenticated users can get a specific snapshot', function () {
    $user = User::factory()->withAbilities([])->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual');
    $snapshot = $snapshots[0];

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/snapshots/{$snapshot->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $snapshot->id)
        ->assertJsonPath('data.database_name', 'testdb');
});

test('delete-snapshots allows deleting a snapshot via api', function () {
    $user = User::factory()->withAbilities([Ability::DeleteSnapshots->value])->create();
    $snapshot = Snapshot::factory()->withFile()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/snapshots/{$snapshot->id}")
        ->assertStatus(202);

    expect(Snapshot::find($snapshot->id))->toBeNull();
});

test('without delete-snapshots, deleting a snapshot via api is forbidden', function () {
    $user = User::factory()->withAllAbilitiesExcept(Ability::DeleteSnapshots->value)->create();
    $snapshot = Snapshot::factory()->withFile()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/snapshots/{$snapshot->id}")
        ->assertForbidden();

    expect(Snapshot::find($snapshot->id))->not->toBeNull();
});

test('a snapshot whose backup is running cannot be deleted via api', function () {
    $user = User::factory()->withAbilities([Ability::DeleteSnapshots->value])->create();
    $snapshot = Snapshot::factory()->withFile()->create();
    $snapshot->job->update(['status' => BackupJobStatus::Running]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/snapshots/{$snapshot->id}")
        ->assertStatus(409);

    expect(Snapshot::find($snapshot->id))->not->toBeNull();
});

test('delete-snapshots allows queueing a bulk delete via api, skipping locked snapshots', function () {
    Queue::fake();
    $user = User::factory()->withAbilities([Ability::DeleteSnapshots->value])->create();
    $deletable = Snapshot::factory()->withFile()->create();
    $locked = Snapshot::factory()->withFile()->create(['locked' => true]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/snapshots/bulk-delete', [
            'ids' => [$deletable->id, $locked->id, 'unknown'],
            'keep_files' => true,
        ])
        ->assertStatus(202)
        ->assertJsonPath('queued', 1);

    Queue::assertPushed(DeleteSnapshotsJob::class, fn (DeleteSnapshotsJob $job) => $job->snapshotIds === [$deletable->id] && $job->keepFiles);
    expect($deletable->fresh()->deleting)->toBeTrue()
        ->and($locked->fresh()->deleting)->toBeFalse();
});

test('without delete-snapshots, bulk deleting via api is forbidden', function () {
    Queue::fake();
    $user = User::factory()->withAllAbilitiesExcept(Ability::DeleteSnapshots->value)->create();
    $snapshot = Snapshot::factory()->withFile()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/snapshots/bulk-delete', ['ids' => [$snapshot->id]])
        ->assertForbidden();

    Queue::assertNothingPushed();
});
