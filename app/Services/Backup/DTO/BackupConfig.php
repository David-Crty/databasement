<?php

namespace App\Services\Backup\DTO;

use App\Enums\CompressionType;

readonly class BackupConfig
{
    public function __construct(
        public DatabaseConnectionConfig $database,
        public VolumeConfig $volume,
        public string $databaseName,
        public string $workingDirectory,
        public string $backupPath = '',
        public ?CompressionType $compressionType = null,
        public ?int $compressionLevel = null,
    ) {}

    /**
     * Serialize to a self-contained agent payload.
     *
     * @return array{
     *     database: array<string, mixed>,
     *     volume: array<string, mixed>,
     *     compression: array{type: string|null, level: int|null},
     *     backup_path: string,
     *     server_name: string,
     * }
     */
    public function toPayload(): array
    {
        return [
            'database' => [
                ...$this->database->toPayload(),
                'database_name' => $this->databaseName,
            ],
            'volume' => $this->volume->toPayload(),
            'compression' => [
                'type' => $this->compressionType?->value,
                'level' => $this->compressionLevel,
            ],
            'backup_path' => $this->backupPath,
            'server_name' => $this->database->serverName,
        ];
    }

    /**
     * Reconstruct from an agent payload.
     *
     * @param  array{
     *     database: array{type: string, host?: string, port?: int, username?: string, password?: string, extra_config?: array<string, mixed>|null, database_name: string},
     *     volume: array{type: string, name?: string, config?: array<string, mixed>},
     *     compression: array{type: string|null, level: int|null},
     *     backup_path?: string,
     *     server_name: string,
     * }  $payload
     */
    public static function fromPayload(array $payload, string $workingDirectory): self
    {
        $dbConfig = $payload['database'];

        return new self(
            database: DatabaseConnectionConfig::fromPayload($dbConfig, $payload['server_name']),
            volume: VolumeConfig::fromPayload($payload['volume']),
            databaseName: $dbConfig['database_name'],
            workingDirectory: $workingDirectory,
            backupPath: $payload['backup_path'] ?? '',
            compressionType: isset($payload['compression']['type'])
                ? CompressionType::from($payload['compression']['type'])
                : null,
            compressionLevel: $payload['compression']['level'] ?? null,
        );
    }
}
