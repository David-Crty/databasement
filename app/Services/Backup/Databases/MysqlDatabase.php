<?php

namespace App\Services\Backup\Databases;

class MysqlDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, array<string, string>> */
    private array $mysqlCli = [
        'mariadb' => [
            'dump' => 'mariadb-dump',
            'restore' => 'mariadb',
        ],
        'mysql' => [
            'dump' => 'mysqldump',
            'restore' => 'mysql',
        ],
    ];

    private function getMysqlCliType(): string
    {
        return config('backup.mysql_cli_type', 'mariadb');
    }

    public function handles(mixed $type): bool
    {
        return strtolower($type ?? '') == 'mysql';
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
        $sslFlag = $this->getMysqlCliType() === 'mariadb' ? '--skip_ssl ' : '';

        return sprintf(
            '%s --routines --add-drop-table --complete-insert --hex-blob --quote-names %s--host=%s --port=%s --user=%s --password=%s %s > %s',
            $this->mysqlCli[$this->getMysqlCliType()]['dump'],
            $sslFlag,
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['database']),
            escapeshellarg($outputPath)
        );
    }

    public function getRestoreCommandLine(string $inputPath): string
    {
        $sslFlag = $this->getMysqlCliType() === 'mariadb' ? '--skip_ssl ' : '';

        return sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s %s%s -e "source %s"',
            $this->mysqlCli[$this->getMysqlCliType()]['restore'],
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            $sslFlag,
            escapeshellarg($this->config['database']),
            $inputPath
        );
    }

    /**
     * Get a command to run STATUS query for connection testing.
     *
     * @param  array<string, mixed>  $config
     */
    public function getStatusCommand(array $config): string
    {
        $cli = $this->mysqlCli[$this->getMysqlCliType()]['restore'];
        $skipSsl = $this->getMysqlCliType() === 'mariadb' ? '--skip_ssl' : '';

        return sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s %s -e %s',
            $cli,
            escapeshellarg($config['host']),
            escapeshellarg((string) $config['port']),
            escapeshellarg($config['user']),
            escapeshellarg($config['pass']),
            $skipSsl,
            escapeshellarg('STATUS;')
        );
    }
}
