<?php

namespace App\Services\Backup\DTO;

use App\Enums\CompressionType;

readonly class BackupConfig
{
    /**
     * @param  list<VolumeConfig>  $volumes  Target volumes. The database is
     *                                       dumped once and the archive is
     *                                       uploaded to each of them.
     */
    public function __construct(
        public DatabaseConnectionConfig $database,
        public array $volumes,
        public string $databaseName,
        public string $workingDirectory,
        public string $backupPath = '',
        public ?CompressionType $compressionType = null,
        public ?int $compressionLevel = null,
        public ?bool $compressionMultithread = null,
        public ?string $postBackupScript = null,
    ) {}

    /**
     * Serialize to a self-contained agent payload.
     *
     * The legacy single `volume` key (first volume) is kept alongside
     * `volumes` so agents that predate multi-volume support keep working.
     *
     * @return array{
     *     database: array<string, mixed>,
     *     volume?: array<string, mixed>,
     *     volumes: list<array<string, mixed>>,
     *     compression: array{type: string|null, level: int|null, multithread: bool|null},
     *     backup_path: string,
     *     server_name: string,
     *     post_backup_script: string|null,
     * }
     */
    public function toPayload(): array
    {
        $payload = [
            'database' => [
                ...$this->database->toPayload(),
                'database_name' => $this->databaseName,
            ],
            'volumes' => array_map(fn (VolumeConfig $volume) => $volume->toPayload(), $this->volumes),
            'compression' => [
                'type' => $this->compressionType?->value,
                'level' => $this->compressionLevel,
                'multithread' => $this->compressionMultithread,
            ],
            'backup_path' => $this->backupPath,
            'server_name' => $this->database->serverName,
            'post_backup_script' => $this->postBackupScript,
        ];

        if ($this->volumes !== []) {
            $payload['volume'] = $this->volumes[0]->toPayload();
        }

        return $payload;
    }

    /**
     * Reconstruct from an agent payload. Payloads created before multi-volume
     * support (still queued in agent_jobs) only carry the single `volume` key.
     *
     * @param  array{
     *     database: array{type: string, host?: string, port?: int, username?: string, password?: string, extra_config?: array<string, mixed>|null, database_name: string},
     *     volume?: array{type: string, name?: string, config?: array<string, mixed>},
     *     volumes?: list<array{type: string, name?: string, config?: array<string, mixed>}>,
     *     compression: array{type: string|null, level: int|null, multithread?: bool|null},
     *     backup_path?: string,
     *     server_name: string,
     *     post_backup_script?: string|null,
     * }  $payload
     */
    public static function fromPayload(array $payload, string $workingDirectory): self
    {
        $dbConfig = $payload['database'];

        $volumePayloads = $payload['volumes']
            ?? (isset($payload['volume']) ? [$payload['volume']] : []);

        return new self(
            database: DatabaseConnectionConfig::fromPayload($dbConfig, $payload['server_name']),
            volumes: array_map(
                fn (array $volumePayload) => VolumeConfig::fromPayload($volumePayload),
                $volumePayloads,
            ),
            databaseName: $dbConfig['database_name'],
            workingDirectory: $workingDirectory,
            backupPath: $payload['backup_path'] ?? '',
            compressionType: isset($payload['compression']['type'])
                ? CompressionType::from($payload['compression']['type'])
                : null,
            compressionLevel: $payload['compression']['level'] ?? null,
            compressionMultithread: $payload['compression']['multithread'] ?? null,
            postBackupScript: $payload['post_backup_script'] ?? null,
        );
    }
}
