<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Services\Backup\Databases\Concerns\TestsConnection;
use App\Services\Backup\DTO\DatabaseOperationResult;

class FirebirdDatabase implements DatabaseInterface
{
    use TestsConnection;

    private const PROBE_QUERY = "SELECT 1 FROM RDB\$DATABASE;\n";

    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        // gbak reads the backup file name `stdout` as the standard output.
        return new DatabaseOperationResult(command: sprintf(
            'gbak -b -g -user %s -password %s %s stdout',
            escapeshellarg((string) ($this->config['user'] ?? '')),
            escapeshellarg((string) ($this->config['pass'] ?? '')),
            DatabaseOperationResult::escapeDatabaseName($this->connectionTarget()),
        ), writesToStdout: true);
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        return new DatabaseOperationResult(command: sprintf(
            'gbak -rep -user %s -password %s %s %s',
            escapeshellarg((string) ($this->config['user'] ?? '')),
            escapeshellarg((string) ($this->config['pass'] ?? '')),
            escapeshellarg($inputPath),
            DatabaseOperationResult::escapeDatabaseName($this->connectionTarget())
        ));
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        // Firebird restore uses gbak -rep to replace an existing target database in one step.
    }

    public function listDatabases(): array
    {
        $configuredNames = $this->config['database_names'] ?? null;
        if (is_array($configuredNames)) {
            return array_values(array_filter(
                array_map(static fn ($name) => is_string($name) ? trim($name) : '', $configuredNames),
                static fn (string $name) => $name !== ''
            ));
        }

        $database = trim((string) ($this->config['database'] ?? ''));

        return $database === '' ? [] : [$database];
    }

    public function testConnection(): array
    {
        return $this->probeConnection($this->buildConnectionProbeCommand(), input: self::PROBE_QUERY);
    }

    private function connectionTarget(): string
    {
        $database = (string) ($this->config['database'] ?? '');
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 3050);

        if ($host === '') {
            return $database;
        }

        return $host.'/'.$port.':'.$database;
    }

    /**
     * Argument list rather than a shell string: isql reads the probe query from
     * stdin, so no shell is needed to pipe it and no argument needs escaping.
     *
     * @return list<string>
     */
    private function buildConnectionProbeCommand(): array
    {
        return [
            'isql',
            '-b',
            '-user', (string) ($this->config['user'] ?? ''),
            '-password', (string) ($this->config['pass'] ?? ''),
            $this->connectionTarget(),
        ];
    }
}
