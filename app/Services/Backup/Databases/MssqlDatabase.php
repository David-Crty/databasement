<?php

namespace App\Services\Backup\Databases;

class MssqlDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    public function handles(mixed $type): bool
    {
        return strtolower($type ?? '') === 'sqlserver';
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
        // Use PHP script to generate SQL dump (MSSQL has no native dump tool like mysqldump)
        $scriptPath = base_path('scripts/mssql-dump.php');

        return sprintf(
            'php %s --host=%s --port=%d --user=%s --password=%s --database=%s --output=%s',
            escapeshellarg($scriptPath),
            escapeshellarg($this->config['host']),
            (int) $this->config['port'],
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['database']),
            escapeshellarg($outputPath)
        );
    }

    public function getRestoreCommandLine(string $inputPath): string
    {
        return sprintf(
            'sqlcmd -S %s,%d -U %s -P %s -d %s -C -i %s',
            escapeshellarg($this->config['host']),
            (int) $this->config['port'],
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['database']),
            escapeshellarg($inputPath)
        );
    }

    /**
     * Get a command to run a query for connection testing.
     *
     * @param  array<string, mixed>  $config
     * @param  int|null  $timeout  Login timeout in seconds (null for no timeout)
     */
    public function getQueryCommand(array $config, string $query, ?int $timeout = null): string
    {
        $timeoutFlag = $timeout !== null ? sprintf(' -l %d', $timeout) : '';

        return sprintf(
            'sqlcmd -S %s,%d -U %s -P %s -C -Q %s%s -h -1 -W',
            escapeshellarg($config['host']),
            (int) $config['port'],
            escapeshellarg($config['user']),
            escapeshellarg($config['pass']),
            escapeshellarg($query),
            $timeoutFlag
        );
    }
}
