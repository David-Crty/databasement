<?php

namespace App\Services\Backup\Databases;

class PostgresqlDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    public function handles(mixed $type): bool
    {
        return in_array(strtolower($type ?? ''), ['postgresql', 'postgres', 'pgsql']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getDumpCommandLine(string $outputPath): string
    {
        return sprintf(
            'PGPASSWORD=%s pg_dump --clean --host=%s --port=%s --username=%s %s -f %s',
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['host']),
            escapeshellarg($this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($outputPath)
        );
    }

    public function getRestoreCommandLine(string $inputPath): string
    {
        return sprintf(
            'PGPASSWORD=%s psql --host=%s --port=%s --user=%s %s -f %s',
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['host']),
            escapeshellarg($this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($inputPath)
        );
    }

    /**
     * Get a command to run a query for connection testing.
     *
     * @param  array<string, mixed>  $config
     */
    public function getQueryCommand(array $config, string $query): string
    {
        return sprintf(
            'PGPASSWORD=%s psql --host=%s --port=%s --user=%s %s -t -c %s',
            escapeshellarg($config['pass']),
            escapeshellarg($config['host']),
            escapeshellarg((string) $config['port']),
            escapeshellarg($config['user']),
            escapeshellarg($config['database']),
            escapeshellarg($query)
        );
    }
}
