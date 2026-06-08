<?php

use App\Jobs\ProcessBackupJob;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('fails with non-existent schedule ID', function () {
    $this->artisan('backups:run', ['schedule' => 'non-existent-id'])
        ->expectsOutput('Backup schedule not found: non-existent-id')
        ->assertExitCode(1);
});

test('returns success when no backups configured for schedule', function () {
    $schedule = BackupSchedule::factory()->create(['name' => 'Empty Schedule']);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutput("No backups configured for schedule: {$schedule->name}.")
        ->assertExitCode(0);
});

test('dispatches backup jobs for a schedule', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create(['database_names' => ['production_db']]);
    $schedule = $server->backups->first()->backupSchedule;

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain("Dispatching 1 backup(s) for schedule: {$schedule->name}")
        ->expectsOutput('All backup jobs dispatched successfully.')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);

    // Verify snapshot was created with scheduled method
    $snapshot = Snapshot::first();
    expect($snapshot->method)->toBe('scheduled')
        ->and($snapshot->database_name)->toBe('production_db');
});

test('dispatches multiple backup jobs for multiple servers on same schedule', function () {
    Queue::fake();

    $schedule = dailySchedule();

    $server1 = DatabaseServer::factory()->create(['name' => 'Server 1', 'database_names' => ['db1']]);
    $server1->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    $server2 = DatabaseServer::factory()->create(['name' => 'Server 2', 'database_names' => ['db2']]);
    $server2->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 2 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 2);
});

test('only runs backups matching the given schedule', function () {
    Queue::fake();

    $dailySchedule = dailySchedule();
    $weeklySchedule = weeklySchedule();

    $dailyServer = DatabaseServer::factory()->create(['database_names' => ['daily_db']]);
    $dailyServer->backups->first()->update(['backup_schedule_id' => $dailySchedule->id]);

    $weeklyServer = DatabaseServer::factory()->create(['database_names' => ['weekly_db']]);
    $weeklyServer->backups->first()->update(['backup_schedule_id' => $weeklySchedule->id]);

    $this->artisan('backups:run', ['schedule' => $dailySchedule->id])
        ->expectsOutputToContain('Dispatching 1 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('dispatches multiple jobs for server with multiple databases', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create([
        'database_names' => ['db1', 'db2', 'db3'],
    ]);
    $schedule = $server->backups->first()->backupSchedule;

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('3 databases')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 3);
});

test('server with no databases does not prevent other backups from running', function () {
    Queue::fake();

    $schedule = dailySchedule();

    // Server with selection_mode=all but no databases found
    $emptyServer = DatabaseServer::factory()->create([
        'name' => 'Empty PostgreSQL',
        'database_selection_mode' => 'all',
        'database_names' => null,
    ]);
    $emptyServer->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    $this->mock(DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->andReturn([]);
    });

    // Server with explicit database names
    $normalServer = DatabaseServer::factory()->create([
        'name' => 'Normal Server',
        'database_names' => ['production_db'],
    ]);
    $normalServer->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    Log::shouldReceive('warning')
        ->once()
        ->with(\Mockery::pattern('/No databases found on server \[Empty PostgreSQL\] for backup \[.+\]\./'));

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 2 backup(s)')
        ->expectsOutput('All backup jobs dispatched successfully.')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);

    $snapshot = Snapshot::first();
    expect($snapshot->database_name)->toBe('production_db');
});

test('server unreachable during all/pattern listing produces a failed preflight snapshot', function () {
    Queue::fake();

    $schedule = dailySchedule();

    $failingServer = DatabaseServer::factory()->create([
        'name' => 'Failing Server',
        'database_selection_mode' => 'all',
        'database_names' => null,
    ]);
    $failingServer->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    $normalServer = DatabaseServer::factory()->create([
        'name' => 'Normal Server',
        'database_selection_mode' => 'all',
        'database_names' => null,
    ]);
    $normalServer->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    $this->mock(DatabaseProvider::class, function ($mock) use ($normalServer, $failingServer) {
        $mock->shouldReceive('listDatabasesForServer')
            ->andReturnUsing(function ($server) use ($normalServer, $failingServer) {
                if ($server->id === $failingServer->id) {
                    throw new \RuntimeException('Connection refused');
                } elseif ($server->id === $normalServer->id) {
                    return ['production_db', 'other_db'];
                }

                return [];
            });
    });

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 2 backup(s)')
        ->expectsOutput('All backup jobs dispatched successfully.')
        ->assertExitCode(0);

    // Only the normal server's snapshots get dispatched to the queue.
    Queue::assertPushed(ProcessBackupJob::class, 2);

    $failingSnapshot = Snapshot::where('database_server_id', $failingServer->id)->first();
    expect($failingSnapshot)->not->toBeNull()
        ->and($failingSnapshot->database_name)->toBe('(all databases)')
        ->and($failingSnapshot->method)->toBe('scheduled')
        ->and($failingSnapshot->job->status)->toBe('failed')
        ->and($failingSnapshot->job->error_message)->toBe('Connection refused');

    expect(Snapshot::where('database_server_id', $normalServer->id)->pluck('database_name')->sort()->values()->all())
        ->toBe(['other_db', 'production_db']);
});

test('skips disabled backups', function () {
    Queue::fake();

    $schedule = dailySchedule();

    $enabledServer = DatabaseServer::factory()->create(['name' => 'Enabled Server', 'database_names' => ['db1'], 'backups_enabled' => true]);
    $enabledServer->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    $disabledServer = DatabaseServer::factory()->create(['name' => 'Disabled Server', 'database_names' => ['db2'], 'backups_enabled' => false]);
    $disabledServer->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 1 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('runs both backup configs when a server has two on the same schedule', function () {
    Queue::fake();

    $schedule = dailySchedule();
    $server = DatabaseServer::factory()->create(['database_names' => ['shared_db']]);
    $backup1 = $server->backups()->firstOrFail();
    $backup1->update(['backup_schedule_id' => $schedule->id]);

    // Attach a second backup config on the same schedule (different volume)
    $volume2 = \App\Models\Volume::factory()->local()->create();
    $backup2 = \App\Models\Backup::factory()->for($server)->create([
        'backup_schedule_id' => $schedule->id,
        'volume_id' => $volume2->id,
        'database_selection_mode' => \App\Enums\DatabaseSelectionMode::Selected->value,
        'database_names' => ['shared_db'],
    ]);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 2 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 2);
    $backupIds = Snapshot::pluck('backup_id')->sort()->values()->all();
    expect($backupIds)->toBe(collect([$backup1->id, $backup2->id])->sort()->values()->all());
});

test('runs only backups matching the given schedule when the server has multiple', function () {
    Queue::fake();

    $dailySchedule = dailySchedule();
    $weeklySchedule = weeklySchedule();

    $server = DatabaseServer::factory()->create(['database_names' => ['prod_db']]);
    $dailyBackup = $server->backups()->firstOrFail();
    $dailyBackup->update(['backup_schedule_id' => $dailySchedule->id]);

    $volume2 = \App\Models\Volume::factory()->local()->create();
    \App\Models\Backup::factory()->for($server)->create([
        'backup_schedule_id' => $weeklySchedule->id,
        'volume_id' => $volume2->id,
        'database_selection_mode' => \App\Enums\DatabaseSelectionMode::Selected->value,
        'database_names' => ['prod_db'],
    ]);

    // Run only the daily schedule
    $this->artisan('backups:run', ['schedule' => $dailySchedule->id])
        ->expectsOutputToContain('Dispatching 1 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
    expect(Snapshot::pluck('backup_id')->all())->toBe([$dailyBackup->id]);
});
