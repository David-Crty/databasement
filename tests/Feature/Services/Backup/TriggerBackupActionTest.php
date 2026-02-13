<?php

use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('it throws exception when server has no backup configuration', function () {
    // Create server without using the factory's afterCreating hook
    $server = DatabaseServer::factory()->make();
    $server->saveQuietly();

    $action = app(TriggerBackupAction::class);

    expect(fn () => $action->execute($server))
        ->toThrow(RuntimeException::class, 'No backup configuration found for this database server.');
});

test('it creates a snapshot and dispatches backup job for single database', function () {
    // Factory automatically creates backup via afterCreating hook
    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
        'backup_all_databases' => false,
    ]);
    $server->load('backup.volume');

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    expect($result['snapshots'])->toHaveCount(1)
        ->and($result['message'])->toBe('Backup started successfully!')
        ->and($result['snapshots'][0]->database_name)->toBe('test_db')
        ->and($result['snapshots'][0]->method)->toBe('manual');

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('it tracks the user who triggered the backup', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
        'backup_all_databases' => false,
    ]);
    $server->load('backup.volume');

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server, $user->id);

    expect($result['snapshots'][0]->triggered_by_user_id)->toBe($user->id);
});

test('it returns correct message for multiple database backups', function () {
    $server = DatabaseServer::factory()->create([
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'password',
        'backup_all_databases' => true,
    ]);
    $server->load('backup.volume');

    // Mock the DatabaseProvider to return multiple databases
    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->andReturn(['db1', 'db2', 'db3']);
    });

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    expect($result['snapshots'])->toHaveCount(3)
        ->and($result['message'])->toBe('3 database backups started successfully!');

    Queue::assertPushed(ProcessBackupJob::class, 3);
});
