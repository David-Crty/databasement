<?php

namespace App\Services\Agent;

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Models\Backup;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\RestoreConfig;
use App\Services\Backup\DTO\VolumeConfig;
use App\Support\Formatters;
use RuntimeException;

class AgentJobPayloadBuilder
{
    /**
     * Build a self-contained work order payload for a backup agent job.
     *
     * @return array{
     *     database: array<string, mixed>,
     *     volume?: array<string, mixed>,
     *     volumes: list<array<string, mixed>>,
     *     compression: array{type: string|null, level: int|null, multithread: bool|null},
     *     backup_path: string,
     *     server_name: string,
     *     post_backup_script: string|null,
     *     excluded_tables: list<string>,
     * }
     */
    public function buildBackup(Snapshot $snapshot): array
    {
        $server = $snapshot->databaseServer;

        $config = new BackupConfig(
            database: DatabaseConnectionConfig::fromServer($server),
            // Ordered by copy id so the first payload volume matches
            // Snapshot::primaryFile() and the legacy ack() fallback.
            volumes: array_values($snapshot->files->sortBy('id')->map(
                fn ($file) => VolumeConfig::fromVolume($file->volume),
            )->all()),
            databaseName: $snapshot->database_name,
            workingDirectory: '',
            backupPath: $this->resolveBackupPath($snapshot->backup->path),
            compressionType: CompressionType::tryFrom(AppConfig::get('backup.compression') ?? ''),
            compressionLevel: AppConfig::get('backup.compression_level'),
            compressionMultithread: (bool) AppConfig::get('backup.compression_multithread'),
            postBackupScript: AppConfig::get('backup.post_backup_script'),
            excludedTables: Backup::parseExcludedTables($snapshot->backup?->excluded_tables),
        );

        return $config->toPayload();
    }

    /**
     * Build a payload for a discovery agent job targeting one backup config.
     *
     * @param  'manual'|'scheduled'  $method
     * @return array{
     *     backup_id: string,
     *     database: array{type: string, host: string, port: int, username: string, password: string, extra_config: array<string, mixed>|null},
     *     selection_mode: string,
     *     pattern: string|null,
     *     server_name: string|null,
     *     method: 'manual'|'scheduled',
     *     triggered_by_user_id: int|null,
     * }
     */
    public function buildDiscovery(Backup $backup, string $method, ?int $triggeredByUserId): array
    {
        $server = $backup->databaseServer;

        return [
            'backup_id' => $backup->id,
            'database' => DatabaseConnectionConfig::fromServer($server)->toPayload(),
            'selection_mode' => $backup->database_selection_mode->value,
            'pattern' => $backup->database_include_pattern,
            'server_name' => $server->name,
            'method' => $method,
            'triggered_by_user_id' => $triggeredByUserId,
        ];
    }

    /**
     * Build a self-contained work order payload for a restore agent job.
     *
     * The source copy is fixed here rather than at run time: the agent cannot
     * look it up, so it reads the user's pick or the first copy it can reach.
     *
     * @return array<string, mixed>
     */
    public function buildRestore(Restore $restore): array
    {
        $copies = $restore->snapshot->files()->completed()->fileExists()->reachableByAgent()->with('volume');

        if ($restore->snapshot_file_id !== null) {
            $copies->whereKey($restore->snapshot_file_id);
        }

        $sourceFile = $copies->oldest('id')->first();

        if ($sourceFile === null) {
            throw new RuntimeException('No copy of this snapshot is available on a volume the agent can reach.');
        }

        return RestoreConfig::fromRestore($restore, $sourceFile, '')->toPayload();
    }

    private function resolveBackupPath(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        return Formatters::resolveDatePlaceholders($path);
    }
}
