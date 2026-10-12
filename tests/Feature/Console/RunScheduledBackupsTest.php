<?php

use App\Actions\Backup\TriggerBackupAction;
use App\Enums\BackupDiscoveryState;
use App\Jobs\ProcessBackupJob;
use App\Models\Agent;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\DTO\TriggerBackupResult;
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
    $backup2 = \App\Models\Backup::factory()->for($server)->withVolumes($volume2)->create([
        'backup_schedule_id' => $schedule->id,
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
    \App\Models\Backup::factory()->for($server)->withVolumes($volume2)->create([
        'backup_schedule_id' => $weeklySchedule->id,
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

test('reports what each backup dispatched', function (bool $viaAgent, Closure $result, string $output) {
    $server = DatabaseServer::factory()->create(['database_names' => ['app'], 'agent_id' => $viaAgent ? Agent::factory()->create()->id : null]);
    TriggerBackupAction::shouldRun()->once()
        ->withArgs(fn (Backup $backup, ?int $userId, string $method) => $method === 'scheduled')
        ->andReturnUsing($result);

    $this->artisan('backups:run', ['schedule' => $server->backups->first()->backup_schedule_id])
        ->expectsOutputToContain($output)
        ->assertExitCode(0);
})->with([
    'several databases' => [false, fn () => new TriggerBackupResult([new Snapshot, new Snapshot, new Snapshot], BackupDiscoveryState::NotNeeded), '(3 databases) via queue'],
    'an agent-backed server' => [true, fn () => new TriggerBackupResult([new Snapshot], BackupDiscoveryState::NotNeeded), '(1 database) via agent'],
    'a discovery dispatched' => [true, fn () => new TriggerBackupResult([], BackupDiscoveryState::Dispatched), 'Dispatched discovery for'],
    'a discovery already running' => [true, fn () => new TriggerBackupResult([], BackupDiscoveryState::AlreadyRunning), 'already in-flight'],
]);

test('a backup that fails to dispatch does not stop the others', function () {
    $schedule = dailySchedule();
    $failing = DatabaseServer::factory()->create(['name' => 'Failing Server', 'database_names' => ['db1']]);
    $failing->backups->first()->update(['backup_schedule_id' => $schedule->id]);
    $healthy = DatabaseServer::factory()->create(['database_names' => ['db2']]);
    $healthy->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    TriggerBackupAction::shouldRun()->twice()->andReturnUsing(fn (Backup $backup) => $backup->database_server_id === $failing->id
        ? throw new RuntimeException('Connection refused')
        : new TriggerBackupResult([new Snapshot], BackupDiscoveryState::NotNeeded));
    Log::shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'Failing Server'));

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatched backup for: '.$healthy->name)
        ->expectsOutput('Completed with 1 failed server(s).')
        ->assertExitCode(0);
});
