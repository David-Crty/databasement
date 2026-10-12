<?php

use App\Facades\AppConfig;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Agent\AgentJobPayloadBuilder;
use App\Services\Backup\DTO\BackupConfig;

test('resolveBackupPath returns empty string when path is empty', function () {
    $server = DatabaseServer::factory()->create([
        'database_names' => ['testdb'],
    ]);
    $server->backups->first()->update(['path' => '']);

    $snapshot = Snapshot::factory()->forServer($server)->create([
        'database_name' => 'testdb',
    ]);

    $builder = new AgentJobPayloadBuilder;
    $payload = $builder->buildBackup($snapshot);

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

    $payload = (new AgentJobPayloadBuilder)->buildBackup($snapshot);

    expect($payload['post_backup_script'])->toBe('echo "$BACKUP_FILENAME"');
});

test('the backup payload carries the backup configuration excluded tables to the agent', function () {
    $server = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'database_names' => ['testdb'],
    ]);
    $server->backups->first()->update(['excluded_tables' => ['audit_log']]);

    $snapshot = Snapshot::factory()->forServer($server)->create([
        'database_name' => 'testdb',
    ]);

    $payload = (new AgentJobPayloadBuilder)->buildBackup($snapshot);

    expect($payload['excluded_tables'])->toBe(['audit_log'])
        ->and(BackupConfig::fromPayload($payload, '/tmp')->excludedTables)->toBe(['audit_log']);
});
