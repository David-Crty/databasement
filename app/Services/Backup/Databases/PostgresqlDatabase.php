<?php

namespace App\Services\Backup\Databases;

class PostgresqlDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private const DUMP_OPTIONS = [
        '--clean',                  // Add DROP statements before CREATE
        '--if-exists',              // Use IF EXISTS with DROP to avoid errors
        '--no-owner',               // Don't output ownership commands (more portable)
        '--no-privileges',          // Don't output GRANT/REVOKE (more portable)
        '--quote-all-identifiers',  // Quote all identifiers (safer for reserved words)
    ];

    public function handles(mixed $type): bool
    {
        return strtolower($type ?? '') === 'postgres';
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
            'PGPASSWORD=%s pg_dump %s --host=%s --port=%s --username=%s %s -f %s',
            escapeshellarg($this->config['pass']),
            implode(' ', self::DUMP_OPTIONS),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($outputPath)
        );
    }

    public function getRestoreCommandLine(string $inputPath): string
    {
        return sprintf(
            'PGPASSWORD=%s psql --host=%s --port=%s --username=%s %s -f %s',
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
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
