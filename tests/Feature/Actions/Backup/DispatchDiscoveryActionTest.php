<?php

use App\Actions\Backup\DispatchDiscoveryAction;
use App\Enums\AgentJobType;
use App\Enums\DatabaseSelectionMode;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\DatabaseServer;

function agentBackedBackup(): Backup
{
    $server = DatabaseServer::factory()->withoutBackups()->create(['agent_id' => Agent::factory()->create()->id]);

    return Backup::factory()->for($server)->create(['database_selection_mode' => DatabaseSelectionMode::All->value]);
}

test('it asks the agent to list the databases of the configuration', function () {
    $backup = agentBackedBackup();

    expect(DispatchDiscoveryAction::run($backup, 'scheduled'))->toBeTrue();

    $agentJob = AgentJob::sole();
    expect($agentJob->type)->toBe(AgentJobType::Discover)
        ->and($agentJob->database_server_id)->toBe($backup->database_server_id)
        ->and($agentJob->payload['backup_id'])->toBe($backup->id)
        ->and($agentJob->payload['method'])->toBe('scheduled');
});

test('a discovery already in flight for the configuration is not dispatched again', function () {
    $backup = agentBackedBackup();
    DispatchDiscoveryAction::run($backup, 'scheduled');

    expect(DispatchDiscoveryAction::run($backup, 'scheduled'))->toBeFalse()
        ->and(AgentJob::count())->toBe(1);
});

test('a finished discovery does not block the next one', function () {
    $backup = agentBackedBackup();
    DispatchDiscoveryAction::run($backup, 'scheduled');
    AgentJob::sole()->update(['status' => AgentJob::STATUS_COMPLETED]);

    expect(DispatchDiscoveryAction::run($backup, 'scheduled'))->toBeTrue()
        ->and(AgentJob::count())->toBe(2);
});

test('a discovery in flight for another configuration of the server does not block', function () {
    $backup = agentBackedBackup();
    $other = Backup::factory()->for($backup->databaseServer)->create(['database_selection_mode' => DatabaseSelectionMode::All->value]);
    DispatchDiscoveryAction::run($other, 'scheduled');

    expect(DispatchDiscoveryAction::run($backup, 'scheduled'))->toBeTrue();
});
