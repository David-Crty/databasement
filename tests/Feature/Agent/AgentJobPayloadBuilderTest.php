<?php

use App\Facades\AppConfig;
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

test('build includes the configured post-backup script so agents run it', function () {
    AppConfig::set('backup.post_backup_script', 'echo "$BACKUP_FILENAME"');

    $server = DatabaseServer::factory()->create([
        'database_names' => ['testdb'],
    ]);

    $snapshot = Snapshot::factory()->forServer($server)->create([
        'database_name' => 'testdb',
    ]);

    $payload = (new AgentJobPayloadBuilder)->build($snapshot);

    expect($payload['post_backup_script'])->toBe('echo "$BACKUP_FILENAME"');
});
