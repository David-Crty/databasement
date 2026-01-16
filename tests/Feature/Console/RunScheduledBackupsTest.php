<?php

use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Queue;

test('fails with invalid recurrence type', function () {
    $this->artisan('backups:run', ['recurrence' => 'monthly'])
        ->expectsOutput("Invalid recurrence type: monthly. Must be 'daily' or 'weekly'.")
        ->assertExitCode(1);
});

test('returns success when no backups configured', function () {
    $this->artisan('backups:run', ['recurrence' => 'daily'])
        ->expectsOutput('No daily backups configured.')
        ->assertExitCode(0);
});

test('dispatches backup jobs for daily backups', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create(['database_names' => ['production_db']]);
    $server->backup->update(['recurrence' => 'daily']);

    $this->artisan('backups:run', ['recurrence' => 'daily'])
        ->expectsOutput('Dispatching 1 daily backup(s)...')
        ->expectsOutput('All backup jobs dispatched successfully.')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);

    // Verify snapshot was created with scheduled method
    $snapshot = Snapshot::first();
    expect($snapshot->method)->toBe('scheduled')
        ->and($snapshot->database_name)->toBe('production_db');
});

test('dispatches backup jobs for weekly backups', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create(['database_names' => ['weekly_db']]);
    $server->backup->update(['recurrence' => 'weekly']);

    $this->artisan('backups:run', ['recurrence' => 'weekly'])
        ->expectsOutput('Dispatching 1 weekly backup(s)...')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('dispatches multiple backup jobs for multiple servers', function () {
    Queue::fake();

    $server1 = DatabaseServer::factory()->create(['name' => 'Server 1', 'database_names' => ['db1']]);
    $server1->backup->update(['recurrence' => 'daily']);

    $server2 = DatabaseServer::factory()->create(['name' => 'Server 2', 'database_names' => ['db2']]);
    $server2->backup->update(['recurrence' => 'daily']);

    $this->artisan('backups:run', ['recurrence' => 'daily'])
        ->expectsOutput('Dispatching 2 daily backup(s)...')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 2);
});

test('only runs backups matching recurrence type', function () {
    Queue::fake();

    $dailyServer = DatabaseServer::factory()->create(['database_names' => ['daily_db']]);
    $dailyServer->backup->update(['recurrence' => 'daily']);

    $weeklyServer = DatabaseServer::factory()->create(['database_names' => ['weekly_db']]);
    $weeklyServer->backup->update(['recurrence' => 'weekly']);

    $this->artisan('backups:run', ['recurrence' => 'daily'])
        ->expectsOutput('Dispatching 1 daily backup(s)...')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('dispatches multiple jobs for server with multiple databases', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create([
        'database_names' => ['db1', 'db2', 'db3'],
    ]);
    $server->backup->update(['recurrence' => 'daily']);

    $this->artisan('backups:run', ['recurrence' => 'daily'])
        ->expectsOutputToContain('3 databases')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 3);
});

test('skips disabled backups', function () {
    Queue::fake();

    $enabledServer = DatabaseServer::factory()->create(['name' => 'Enabled Server', 'database_names' => ['db1'], 'backups_enabled' => true]);
    $enabledServer->backup->update(['recurrence' => 'daily']);

    $disabledServer = DatabaseServer::factory()->create(['name' => 'Disabled Server', 'database_names' => ['db2'], 'backups_enabled' => false]);
    $disabledServer->backup->update(['recurrence' => 'daily']);

    $this->artisan('backups:run', ['recurrence' => 'daily'])
        ->expectsOutput('Dispatching 1 daily backup(s)...')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});
