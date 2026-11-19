<?php

namespace App\Services\Backup\DTO;

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Models\Restore;
use App\Models\SnapshotFile;

readonly class RestoreConfig
{
    /**
     * @param  list<string>  $restoreSchemas
     * @param  list<array{schema: string, table: string}>  $restoreTables
     * @param  list<array{schema: string, table: string}>  $excludeRestoreTables
     */
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
        public array $restoreSchemas = [],
        public array $restoreTables = [],
        public array $excludeRestoreTables = [],
    ) {}

    public static function fromRestore(Restore $restore, SnapshotFile $sourceFile, string $workingDirectory): self
    {
        $snapshot = $restore->snapshot;
        $options = RestoreOptions::fromArray((array) ($restore->options ?? []));

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
            forceDatabase: $options->forceDatabase,
            ownerUser: $options->ownerUser,
            snapshotDumpFormat: is_string($format = ($snapshot->metadata['dump_format'] ?? null)) ? $format : null,
            snapshotDumpPrivileges: (bool) ($snapshot->metadata['dump_privileges'] ?? false),
            postRestoreScript: AppConfig::get('backup.post_restore_script'),
            parallelRestore: $options->parallelRestore,
            restoreSchemas: $options->restoreSchemas,
            restoreTables: $options->restoreTables,
            excludeRestoreTables: $options->excludeRestoreTables,
        );
    }

    /**
     * Serialize to a self-contained agent payload.
     *
     * @return array<string, mixed>
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
            'restore_schemas' => $this->restoreSchemas,
            'restore_tables' => $this->restoreTables,
            'exclude_restore_tables' => $this->excludeRestoreTables,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, string $workingDirectory): self
    {
        $snapshot = $payload['snapshot'];
        $options = RestoreOptions::fromArray([
            'force_database' => $payload['force_database'] ?? false,
            'owner_user' => $payload['owner_user'] ?? null,
            'parallel_restore' => $payload['parallel_restore'] ?? false,
            'restore_schemas' => $payload['restore_schemas'] ?? [],
            'restore_tables' => $payload['restore_tables'] ?? [],
            'exclude_restore_tables' => $payload['exclude_restore_tables'] ?? [],
        ]);

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
            forceDatabase: $options->forceDatabase,
            ownerUser: $options->ownerUser,
            snapshotDumpFormat: $snapshot['dump_format'] ?? null,
            snapshotDumpPrivileges: (bool) ($snapshot['dump_privileges'] ?? false),
            postRestoreScript: $payload['post_restore_script'] ?? null,
            parallelRestore: $options->parallelRestore,
            restoreSchemas: $options->restoreSchemas,
            restoreTables: $options->restoreTables,
            excludeRestoreTables: $options->excludeRestoreTables,
        );
    }
}
