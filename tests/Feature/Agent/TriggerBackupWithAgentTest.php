<?php

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Support\Facades\Queue;

test('backup dispatch creates agent jobs when server has agent', function () {
    Queue::fake();

    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create([
        'agent_id' => $agent->id,
        'database_names' => ['testdb'],
    ]);

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    // Should create AgentJob records, not dispatch queue jobs
    expect($result['snapshots'])->not->toBeEmpty();
    expect(AgentJob::count())->toBe(count($result['snapshots']));

    // Verify each snapshot has a corresponding agent job
    foreach ($result['snapshots'] as $snapshot) {
        $agentJob = AgentJob::where('snapshot_id', $snapshot->id)->first();
        expect($agentJob)->not->toBeNull()
            ->and($agentJob->status)->toBe(AgentJob::STATUS_PENDING)
            ->and($agentJob->payload)->toBeArray()
            ->and($agentJob->payload['database']['type'])->toBe($server->database_type->value)
            ->and($agentJob->payload['server_name'])->toBe($server->name);
    }

    Queue::assertNothingPushed();
});

test('backup dispatch uses queue when server has no agent', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create([
        'agent_id' => null,
        'database_names' => ['testdb'],
    ]);

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    expect($result['snapshots'])->not->toBeEmpty();
    expect(AgentJob::count())->toBe(0);

    Queue::assertPushed(\App\Jobs\ProcessBackupJob::class);
});

test('agent job payload contains decrypted database credentials', function () {
    Queue::fake();

    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create([
        'agent_id' => $agent->id,
        'password' => 'secret-password',
        'database_names' => ['testdb'],
    ]);

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    $agentJob = AgentJob::first();
    expect($agentJob->payload['database']['password'])->toBe('secret-password');
});

test('agent job payload contains volume config', function () {
    Queue::fake();

    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->create([
        'agent_id' => $agent->id,
        'database_names' => ['testdb'],
    ]);

    $action = app(TriggerBackupAction::class);
    $result = $action->execute($server);

    $agentJob = AgentJob::first();
    expect($agentJob->payload['volume'])->toHaveKeys(['type', 'config']);
});
