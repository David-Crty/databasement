<?php

namespace App\Services\Agent;

use App\Facades\AppConfig;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;

class AgentJobPayloadBuilder
{
    /**
     * Build a self-contained work order payload for an agent job.
     *
     * @return array{database: array<string, mixed>, volume: array<string, mixed>, compression: array{type: string, level: int}, backup_path: string, server_name: string, dump_extension: string}
     */
    public function build(Snapshot $snapshot): array
    {
        $server = $snapshot->databaseServer;
        $volume = $snapshot->volume;

        return [
            'database' => $this->buildDatabaseConfig($server, $snapshot->database_name),
            'volume' => $this->buildVolumeConfig($volume),
            'compression' => [
                'type' => AppConfig::get('backup.compression'),
                'level' => AppConfig::get('backup.compression_level'),
            ],
            'backup_path' => $this->resolveBackupPath($server),
            'server_name' => $server->name,
            'dump_extension' => $server->database_type->dumpExtension(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDatabaseConfig(DatabaseServer $server, string $databaseName): array
    {
        return [
            'type' => $server->database_type->value,
            'host' => $server->host,
            'port' => $server->port,
            'username' => $server->username,
            'password' => $server->getDecryptedPassword(),
            'database_name' => $databaseName,
            'extra_config' => $server->extra_config,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVolumeConfig(Volume $volume): array
    {
        return [
            'type' => $volume->type,
            'name' => $volume->name,
            'config' => $volume->getDecryptedConfig(),
        ];
    }

    private function resolveBackupPath(DatabaseServer $server): string
    {
        $path = $server->backup->path ?? '';

        if (empty($path)) {
            return '';
        }

        return str_replace(
            ['{year}', '{month}', '{day}'],
            [now()->format('Y'), now()->format('m'), now()->format('d')],
            $path
        );
    }
}
