<?php

namespace App\Services\Agent;

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\VolumeConfig;
use App\Support\Formatters;

class AgentJobPayloadBuilder
{
    /**
     * Build a self-contained work order payload for a backup agent job.
     *
     * @return array<string, mixed>
     */
    public function build(Snapshot $snapshot): array
    {
        $server = $snapshot->databaseServer;

        $config = new BackupConfig(
            database: DatabaseConnectionConfig::fromServer($server),
            volume: VolumeConfig::fromVolume($snapshot->volume),
            databaseName: $snapshot->database_name,
            workingDirectory: '',
            backupPath: $this->resolveBackupPath($server),
            compressionType: CompressionType::tryFrom(AppConfig::get('backup.compression') ?? ''),
            compressionLevel: AppConfig::get('backup.compression_level'),
        );

        return $config->toPayload();
    }

    /**
     * Build a payload for a discovery agent job.
     *
     * @param  'manual'|'scheduled'  $method
     * @return array<string, mixed>
     */
    public function buildDiscovery(DatabaseServer $server, string $method, ?int $triggeredByUserId): array
    {
        return [
            'type' => 'discover',
            'database' => DatabaseConnectionConfig::fromServer($server)->toPayload(),
            'selection_mode' => $server->database_selection_mode->value,
            'pattern' => $server->database_include_pattern,
            'server_name' => $server->name,
            'method' => $method,
            'triggered_by_user_id' => $triggeredByUserId,
        ];
    }

    private function resolveBackupPath(DatabaseServer $server): string
    {
        $path = $server->backup->path ?? '';

        if (empty($path)) {
            return '';
        }

        return Formatters::resolveDatePlaceholders($path);
    }
}
