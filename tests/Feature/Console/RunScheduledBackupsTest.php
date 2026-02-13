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
    $schedule = $server->backup->backupSchedule;

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
    $server1->backup->update(['backup_schedule_id' => $schedule->id]);

    $server2 = DatabaseServer::factory()->create(['name' => 'Server 2', 'database_names' => ['db2']]);
    $server2->backup->update(['backup_schedule_id' => $schedule->id]);

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
    $dailyServer->backup->update(['backup_schedule_id' => $dailySchedule->id]);

    $weeklyServer = DatabaseServer::factory()->create(['database_names' => ['weekly_db']]);
    $weeklyServer->backup->update(['backup_schedule_id' => $weeklySchedule->id]);

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
    $schedule = $server->backup->backupSchedule;

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('3 databases')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 3);
});

test('server with no databases does not prevent other backups from running', function () {
    Queue::fake();

    $schedule = dailySchedule();

    // Server with backup_all_databases but no databases found
    $emptyServer = DatabaseServer::factory()->create([
        'name' => 'Empty PostgreSQL',
        'backup_all_databases' => true,
        'database_names' => null,
    ]);
    $emptyServer->backup->update(['backup_schedule_id' => $schedule->id]);

    $this->mock(DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->andReturn([]);
    });

    // Server with explicit database names
    $normalServer = DatabaseServer::factory()->create([
        'name' => 'Normal Server',
        'database_names' => ['production_db'],
    ]);
    $normalServer->backup->update(['backup_schedule_id' => $schedule->id]);

    Log::shouldReceive('warning')
        ->once()
        ->with('No databases found on server [Empty PostgreSQL] to backup.');

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 2 backup(s)')
        ->expectsOutput('All backup jobs dispatched successfully.')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);

    $snapshot = Snapshot::first();
    expect($snapshot->database_name)->toBe('production_db');
});

test('server throwing exception when listing databases does not prevent other backups from running', function () {
    Queue::fake();

    $schedule = dailySchedule();

    // Server with backup_all_databases that will throw an exception
    $failingServer = DatabaseServer::factory()->create([
        'name' => 'Failing Server',
        'backup_all_databases' => true,
        'database_names' => null,
    ]);
    $failingServer->backup->update(['backup_schedule_id' => $schedule->id]);

    // Server with explicit database names that should still work
    $normalServer = DatabaseServer::factory()->create([
        'name' => 'Normal Server',
        'backup_all_databases' => true,
        'database_names' => null,
    ]);
    $normalServer->backup->update(['backup_schedule_id' => $schedule->id]);

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

    Log::shouldReceive('error')
        ->once()
        ->with('Failed to dispatch backup job for server [Failing Server]', ['error' => 'Connection refused']);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 2 backup(s)')
        ->expectsOutput('Completed with 1 failed server(s).')
        ->assertExitCode(0);

    // Only the normal server's backup should be dispatched
    Queue::assertPushed(ProcessBackupJob::class, 2);

    $snapshot = Snapshot::first();
    expect($snapshot->database_name)->toBe('production_db');
});

test('skips disabled backups', function () {
    Queue::fake();

    $schedule = dailySchedule();

    $enabledServer = DatabaseServer::factory()->create(['name' => 'Enabled Server', 'database_names' => ['db1'], 'backups_enabled' => true]);
    $enabledServer->backup->update(['backup_schedule_id' => $schedule->id]);

    $disabledServer = DatabaseServer::factory()->create(['name' => 'Disabled Server', 'database_names' => ['db2'], 'backups_enabled' => false]);
    $disabledServer->backup->update(['backup_schedule_id' => $schedule->id]);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 1 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});
