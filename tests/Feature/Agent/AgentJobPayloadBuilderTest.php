<?php

use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Agent\AgentJobPayloadBuilder;

test('resolveBackupPath returns empty string when path is empty', function () {
    $server = DatabaseServer::factory()->create([
        'database_names' => ['testdb'],
    ]);
    $server->backups->first()->update(['path' => '']);

    $snapshot = Snapshot::factory()->forServer($server)->create([
        'database_name' => 'testdb',
    ]);

    $builder = new AgentJobPayloadBuilder;
    $payload = $builder->build($snapshot);

    expect($payload['backup_path'])->toBe('');
});
