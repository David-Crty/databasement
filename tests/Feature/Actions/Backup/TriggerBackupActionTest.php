<?php

use App\Actions\Backup\TriggerBackupAction;
use App\Enums\AgentJobType;
use App\Enums\BackupDiscoveryState;
use App\Enums\BackupJobStatus;
use App\Enums\DatabaseSelectionMode;
use App\Jobs\ProcessBackupJob;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('it creates a snapshot and dispatches backup job for single database', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    $result = TriggerBackupAction::run($backup);

    expect($result->snapshots)->toHaveCount(1)
        ->and($result->message())->toBe('Backup started successfully!')
        ->and($result->snapshots[0]->database_name)->toBe('test_db')
        ->and($result->snapshots[0]->method)->toBe('manual')
        ->and($result->snapshots[0]->backup_id)->toBe($backup->id);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('it tracks the user who triggered the backup', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    $result = TriggerBackupAction::run($backup, $user->id);

    expect($result->snapshots[0]->triggered_by_user_id)->toBe($user->id);
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

    // Mock the DatabaseProvider to return multiple databases
    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->andReturn(['db1', 'db2', 'db3']);
    });

    $result = TriggerBackupAction::run($backup);

    expect($result->snapshots)->toHaveCount(3)
        ->and($result->message())->toBe('3 database backups started successfully!');

    Queue::assertPushed(ProcessBackupJob::class, 3);
});

test('pattern mode creates snapshots only for matching databases', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->pattern('^prod_')->create();

    $this->mock(\App\Services\Backup\Databases\DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')
            ->andReturn(['prod_users', 'prod_orders', 'test_db', 'staging_db']);
    });

    $result = TriggerBackupAction::run($backup);

    expect($result->snapshots)->toHaveCount(2)
        ->and($result->snapshots[0]->database_name)->toBe('prod_users')
        ->and($result->snapshots[1]->database_name)->toBe('prod_orders');

    Queue::assertPushed(ProcessBackupJob::class, 2);
});

test('agent server with all mode dispatches discovery job instead of snapshots', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create([
        'agent_id' => $agent->id,
    ]);
    $backup = Backup::factory()->for($server)->create([
        'database_selection_mode' => DatabaseSelectionMode::All->value,
    ]);

    $result = TriggerBackupAction::run($backup);

    expect($result->snapshots)->toBeEmpty()
        ->and($result->discovery)->toBe(BackupDiscoveryState::Dispatched)
        ->and($result->message())->toContain('discovery');

    Queue::assertNothingPushed();

    $discoveryJob = AgentJob::where('database_server_id', $server->id)->sole();
    expect($discoveryJob->type)->toBe(AgentJobType::Discover)
        ->and($discoveryJob->snapshot_id)->toBeNull()
        ->and($discoveryJob->payload['selection_mode'])->toBe('all')
        ->and($discoveryJob->payload['backup_id'])->toBe($backup->id);
});

test('agent server with a discovery already in flight reports it instead of a new dispatch', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['agent_id' => $agent->id]);
    $backup = Backup::factory()->for($server)->create([
        'database_selection_mode' => DatabaseSelectionMode::All->value,
    ]);
    AgentJob::factory()->create([
        'type' => AgentJobType::Discover,
        'database_server_id' => $server->id,
        'status' => AgentJob::STATUS_PENDING,
        'payload' => ['backup_id' => $backup->id],
    ]);

    $result = TriggerBackupAction::run($backup);

    expect($result->discovery)->toBe(BackupDiscoveryState::AlreadyRunning)
        ->and($result->message())->toContain('already running')
        ->and(AgentJob::where('database_server_id', $server->id)->count())->toBe(1);
});

test('agent server with pattern mode dispatches discovery job', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create([
        'agent_id' => $agent->id,
    ]);
    $backup = Backup::factory()->for($server)->pattern('^prod_')->create();

    $result = TriggerBackupAction::run($backup);

    expect($result->snapshots)->toBeEmpty();

    Queue::assertNothingPushed();

    $discoveryJob = AgentJob::where('database_server_id', $server->id)->sole();
    expect($discoveryJob->payload['selection_mode'])->toBe('pattern')
        ->and($discoveryJob->payload['pattern'])->toBe('^prod_')
        ->and($discoveryJob->payload['backup_id'])->toBe($backup->id);
});

test('agent server with selected mode creates backup agent jobs directly', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create([
        'agent_id' => $agent->id,
    ]);
    $backup = Backup::factory()->for($server)->selected(['db1', 'db2'])->create();

    $result = TriggerBackupAction::run($backup);

    expect($result->snapshots)->toHaveCount(2)
        ->and($result->message())->toBe('2 database backups started successfully!');

    Queue::assertNothingPushed();

    $agentJobs = AgentJob::where('database_server_id', $server->id)->get();
    expect($agentJobs)->toHaveCount(2)
        ->and($agentJobs[0]->type)->toBe(AgentJobType::Backup)
        ->and($agentJobs[0]->snapshot_id)->not->toBeNull();
});

test('the discovery message stays in English whatever the app locale', function () {
    app()->setLocale('fr');
    $server = DatabaseServer::factory()->withoutBackups()->create(['agent_id' => Agent::factory()->create()->id]);
    $backup = Backup::factory()->for($server)->create(['database_selection_mode' => DatabaseSelectionMode::All->value]);

    expect(TriggerBackupAction::run($backup)->message())
        ->toBe('Database discovery dispatched to agent. Backups will start once databases are discovered.');
});

test('a server unreachable while listing its databases gets a failed snapshot for the run', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->create(['database_selection_mode' => DatabaseSelectionMode::All->value]);
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->andThrow(new RuntimeException('Connection refused'));

    $result = TriggerBackupAction::run($backup, method: 'scheduled');

    $snapshot = Snapshot::where('database_server_id', $server->id)->sole();
    expect($result->snapshots)->toBeEmpty()
        ->and($snapshot->database_name)->toBe('(all databases)')
        ->and($snapshot->method)->toBe('scheduled')
        ->and($snapshot->job->status)->toBe(BackupJobStatus::Failed)
        ->and($snapshot->job->error_message)->toBe('Connection refused');
    Queue::assertNothingPushed();
});

test('a server with no database to back up starts nothing', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create(['name' => 'Empty PostgreSQL']);
    $backup = Backup::factory()->for($server)->create(['database_selection_mode' => DatabaseSelectionMode::All->value]);
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->andReturn([]);
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/No databases found on server \[Empty PostgreSQL\] for backup \[.+\]\./'));

    expect(TriggerBackupAction::run($backup)->snapshots)->toBeEmpty()
        ->and(Snapshot::count())->toBe(0);
    Queue::assertNothingPushed();
});
