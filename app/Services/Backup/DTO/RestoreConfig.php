<?php

namespace App\Services\Backup\DTO;

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Models\Restore;
use App\Models\SnapshotFile;

readonly class RestoreConfig
{
    public function __construct(
        public DatabaseConnectionConfig $targetServer,
        public VolumeConfig $snapshotVolume,
        public string $snapshotFilename,
        public int $snapshotFileSize,
        public CompressionType $snapshotCompressionType,
        public DatabaseType $snapshotDatabaseType,
        public string $snapshotDatabaseName,
        public string $schemaName,
        public string $workingDirectory,
        public bool $forceDatabase = false,
        public ?string $ownerUser = null,
        public ?string $snapshotDumpFormat = null,
        public bool $snapshotDumpPrivileges = false,
        public ?string $postRestoreScript = null,
        public bool $parallelRestore = false,
    ) {}

    public static function fromRestore(Restore $restore, SnapshotFile $sourceFile, string $workingDirectory): self
    {
        $snapshot = $restore->snapshot;

        return new self(
            targetServer: DatabaseConnectionConfig::fromServer($restore->targetServer),
            snapshotVolume: VolumeConfig::fromVolume($sourceFile->volume),
            snapshotFilename: $sourceFile->storedFilename(),
            snapshotFileSize: $snapshot->file_size,
            snapshotCompressionType: $snapshot->compression_type,
            snapshotDatabaseType: $snapshot->database_type,
            snapshotDatabaseName: $snapshot->database_name,
            schemaName: $restore->schema_name,
            workingDirectory: $workingDirectory,
            forceDatabase: filter_var($restore->getOption('force_database', false), FILTER_VALIDATE_BOOLEAN),
            ownerUser: is_string($value = $restore->getOption('owner_user')) && $value !== '' ? $value : null,
            snapshotDumpFormat: is_string($format = ($snapshot->metadata['dump_format'] ?? null)) ? $format : null,
            snapshotDumpPrivileges: (bool) ($snapshot->metadata['dump_privileges'] ?? false),
            postRestoreScript: AppConfig::get('backup.post_restore_script'),
            parallelRestore: filter_var($restore->getOption('parallel_restore', false), FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * Serialize to a self-contained agent payload.
     *
     * @return array{
     *     server_name: string,
     *     database: array<string, mixed>,
     *     volume: array<string, mixed>,
     *     snapshot: array{filename: string, file_size: int, compression_type: string, database_type: string, database_name: string, dump_format: string|null, dump_privileges: bool},
     *     schema_name: string,
     *     force_database: bool,
     *     owner_user: string|null,
     *     post_restore_script: string|null,
     *     parallel_restore: bool,
     * }
     */
    public function toPayload(): array
    {
        return [
            'server_name' => $this->targetServer->serverName,
            'database' => $this->targetServer->toPayload(),
            'volume' => $this->snapshotVolume->toPayload(),
            'snapshot' => [
                'filename' => $this->snapshotFilename,
                'file_size' => $this->snapshotFileSize,
                'compression_type' => $this->snapshotCompressionType->value,
                'database_type' => $this->snapshotDatabaseType->value,
                'database_name' => $this->snapshotDatabaseName,
                'dump_format' => $this->snapshotDumpFormat,
                'dump_privileges' => $this->snapshotDumpPrivileges,
            ],
            'schema_name' => $this->schemaName,
            'force_database' => $this->forceDatabase,
            'owner_user' => $this->ownerUser,
            'post_restore_script' => $this->postRestoreScript,
            'parallel_restore' => $this->parallelRestore,
        ];
    }

    /**
     * @param  array{
     *     server_name: string,
     *     database: array{type: string, host?: string, port?: int, username?: string, password?: string, extra_config?: array<string, mixed>|null, id?: string},
     *     volume: array{id?: string|null, type: string, name?: string, config?: array<string, mixed>},
     *     snapshot: array{filename: string, file_size: int, compression_type: string, database_type: string, database_name: string, dump_format?: string|null, dump_privileges?: bool},
     *     schema_name: string,
     *     force_database?: bool,
     *     owner_user?: string|null,
     *     post_restore_script?: string|null,
     *     parallel_restore?: bool,
     * }  $payload
     */
    public static function fromPayload(array $payload, string $workingDirectory): self
    {
        $snapshot = $payload['snapshot'];

        return new self(
            targetServer: DatabaseConnectionConfig::fromPayload($payload['database'], $payload['server_name']),
            snapshotVolume: VolumeConfig::fromPayload($payload['volume']),
            snapshotFilename: $snapshot['filename'],
            snapshotFileSize: (int) $snapshot['file_size'],
            snapshotCompressionType: CompressionType::from($snapshot['compression_type']),
            snapshotDatabaseType: DatabaseType::from($snapshot['database_type']),
            snapshotDatabaseName: $snapshot['database_name'],
            schemaName: $payload['schema_name'],
            workingDirectory: $workingDirectory,
            forceDatabase: (bool) ($payload['force_database'] ?? false),
            ownerUser: $payload['owner_user'] ?? null,
            snapshotDumpFormat: $snapshot['dump_format'] ?? null,
            snapshotDumpPrivileges: (bool) ($snapshot['dump_privileges'] ?? false),
            postRestoreScript: $payload['post_restore_script'] ?? null,
            parallelRestore: (bool) ($payload['parallel_restore'] ?? false),
        );
    }
}
