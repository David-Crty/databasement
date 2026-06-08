<?php

use App\Enums\DatabaseSelectionMode;
use App\Jobs\ProcessBackupJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('it creates a snapshot and dispatches backup job for single database', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($backup);

    expect($result['snapshots'])->toHaveCount(1)
        ->and($result['message'])->toBe('Backup started successfully!')
        ->and($result['snapshots'][0]->database_name)->toBe('test_db')
        ->and($result['snapshots'][0]->method)->toBe('manual')
        ->and($result['snapshots'][0]->backup_id)->toBe($backup->id);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('it tracks the user who triggered the backup', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($backup, $user->id);

    expect($result['snapshots'][0]->triggered_by_user_id)->toBe($user->id);
});

test('it returns correct message for multiple database backups', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create([
        'host' => 'localhost',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'root',
        'password' => 'password',
    ]);
    $backup = Backup::factory()->for($server)->create([
        'database_selection_mode' => DatabaseSelectionMode::All->value,
    ]);

    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->andReturn(['db1', 'db2', 'db3']);
    });

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($backup);

    expect($result['snapshots'])->toHaveCount(3)
        ->and($result['message'])->toBe('3 database backups started successfully!');

    Queue::assertPushed(ProcessBackupJob::class, 3);
});

test('pattern mode creates snapshots only for matching databases', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->pattern('^prod_')->create();

    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')
            ->andReturn(['prod_users', 'prod_orders', 'test_db', 'staging_db']);
    });

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($backup);

    expect($result['snapshots'])->toHaveCount(2)
        ->and($result['snapshots'][0]->database_name)->toBe('prod_users')
        ->and($result['snapshots'][1]->database_name)->toBe('prod_orders');

    Queue::assertPushed(ProcessBackupJob::class, 2);
});
