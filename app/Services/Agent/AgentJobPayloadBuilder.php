<?php

namespace App\Services\Agent;

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Models\Backup;
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
            backupPath: $this->resolveBackupPath($snapshot->backup?->path),
            compressionType: CompressionType::tryFrom(AppConfig::get('backup.compression') ?? ''),
            compressionLevel: AppConfig::get('backup.compression_level'),
        );

        return $config->toPayload();
    }

    /**
     * Build a payload for a discovery agent job targeting one backup config.
     *
     * @param  'manual'|'scheduled'  $method
     * @return array<string, mixed>
     */
    public function buildDiscovery(Backup $backup, string $method, ?int $triggeredByUserId): array
    {
        $server = $backup->databaseServer;

        return [
            'type' => 'discover',
            'backup_id' => $backup->id,
            'database' => DatabaseConnectionConfig::fromServer($server)->toPayload(),
            'selection_mode' => $backup->database_selection_mode->value,
            'pattern' => $backup->database_include_pattern,
            'server_name' => $server->name,
            'method' => $method,
            'triggered_by_user_id' => $triggeredByUserId,
        ];
    }

    private function resolveBackupPath(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        return Formatters::resolveDatePlaceholders($path);
    }
}
