<?php

use App\Jobs\ProcessBackupJob;
use App\Models\Agent;
use App\Models\AgentJob;
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
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('it creates a snapshot and dispatches backup job for single database', function () {
    // Factory automatically creates backup via afterCreating hook
    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
        'database_selection_mode' => 'selected',
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
        'database_selection_mode' => 'selected',
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
        'database_selection_mode' => 'all',
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

test('pattern mode creates snapshots only for matching databases', function () {
    $server = DatabaseServer::factory()->create([
        'database_selection_mode' => 'pattern',
        'database_include_pattern' => '^prod_',
        'database_names' => null,
    ]);
    $server->load('backup.volume');

    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')
            ->andReturn(['prod_users', 'prod_orders', 'test_db', 'staging_db']);
    });

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    expect($result['snapshots'])->toHaveCount(2)
        ->and($result['snapshots'][0]->database_name)->toBe('prod_users')
        ->and($result['snapshots'][1]->database_name)->toBe('prod_orders');

    Queue::assertPushed(ProcessBackupJob::class, 2);
});

test('agent server with all mode dispatches discovery job instead of snapshots', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create([
        'database_selection_mode' => 'all',
        'agent_id' => $agent->id,
    ]);
    $server->load('backup.volume');

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    expect($result['snapshots'])->toBeEmpty()
        ->and($result['message'])->toContain('discovery');

    Queue::assertNothingPushed();

    $discoveryJob = AgentJob::where('database_server_id', $server->id)->sole();
    expect($discoveryJob->type)->toBe(AgentJob::TYPE_DISCOVER)
        ->and($discoveryJob->snapshot_id)->toBeNull()
        ->and($discoveryJob->payload['type'])->toBe('discover')
        ->and($discoveryJob->payload['selection_mode'])->toBe('all');
});

test('agent server with pattern mode dispatches discovery job', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create([
        'database_selection_mode' => 'pattern',
        'database_include_pattern' => '^prod_',
        'agent_id' => $agent->id,
    ]);
    $server->load('backup.volume');

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    expect($result['snapshots'])->toBeEmpty();

    $discoveryJob = AgentJob::where('database_server_id', $server->id)->sole();
    expect($discoveryJob->payload['selection_mode'])->toBe('pattern')
        ->and($discoveryJob->payload['pattern'])->toBe('^prod_');
});

test('agent server with selected mode creates backup agent jobs directly', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create([
        'database_names' => ['db1', 'db2'],
        'database_selection_mode' => 'selected',
        'agent_id' => $agent->id,
    ]);
    $server->load('backup.volume');

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    expect($result['snapshots'])->toHaveCount(2)
        ->and($result['message'])->toBe('2 database backups started successfully!');

    Queue::assertNothingPushed();

    $agentJobs = AgentJob::where('database_server_id', $server->id)->get();
    expect($agentJobs)->toHaveCount(2)
        ->and($agentJobs[0]->type)->toBe(AgentJob::TYPE_BACKUP)
        ->and($agentJobs[0]->snapshot_id)->not->toBeNull();
});
