<?php

namespace App\Services\Backup\Databases;

class MysqlDatabase implements DatabaseInterface
{
    private array $config;

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

    private string $mysqlCliUsed;

    public function __construct()
    {
        $this->mysqlCliUsed = config('backup.mysql_cli_type', 'mariadb');
    }

    public function handles($type): bool
    {
        return strtolower($type ?? '') == 'mysql';
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getDumpCommandLine($outputPath): string
    {
        $extras = [];
        if (array_key_exists('singleTransaction', $this->config) && $this->config['singleTransaction'] === true) {
            $extras[] = '--single-transaction';
        }
        if (array_key_exists('ignoreTables', $this->config)) {
            $extras[] = $this->getIgnoreTableParameter();
        }
        if (array_key_exists('ssl', $this->config) && $this->config['ssl'] === true) {
            $extras[] = '--ssl';
        } elseif ($this->mysqlCliUsed === 'mariadb') {
            $extras[] = '--skip_ssl';
        }
        if (array_key_exists('extraParams', $this->config) && $this->config['extraParams']) {
            $extras[] = $this->config['extraParams'];
        }

        // Prepare a "params" string from our config
        $params = '';
        $keys = ['host' => 'host', 'port' => 'port', 'user' => 'user', 'pass' => 'password'];
        foreach ($keys as $key => $mysqlParam) {
            if (! empty($this->config[$key])) {
                $params .= sprintf(' --%s=%s', $mysqlParam, escapeshellarg($this->config[$key]));
            }
        }

        $command = $this->mysqlCli[$this->mysqlCliUsed]['dump'].' --routines '.implode(' ', $extras).'%s %s > %s';

        return sprintf(
            $command,
            $params,
            escapeshellarg($this->config['database']),
            escapeshellarg($outputPath)
        );
    }

    public function getRestoreCommandLine($inputPath): string
    {
        $extras = [];
        if (array_key_exists('ssl', $this->config) && $this->config['ssl'] === true) {
            $extras[] = '--ssl';
        } elseif ($this->mysqlCliUsed === 'mariadb') {
            $extras[] = '--skip_ssl';
        }

        // Prepare a "params" string from our config
        $params = '';
        $keys = ['host' => 'host', 'port' => 'port', 'user' => 'user', 'pass' => 'password'];
        foreach ($keys as $key => $mysqlParam) {
            if (! empty($this->config[$key])) {
                $params .= sprintf(' --%s=%s', $mysqlParam, escapeshellarg($this->config[$key]));
            }
        }

        return sprintf(
            '%s%s '.implode(' ', $extras).' %s -e "source %s"',
            $this->mysqlCli[$this->mysqlCliUsed]['restore'],
            $params,
            escapeshellarg($this->config['database']),
            escapeshellarg($inputPath)
        );
    }

    public function getIgnoreTableParameter(): string
    {
        if (! is_array($this->config['ignoreTables']) || count($this->config['ignoreTables']) === 0) {
            return '';
        }

        $db = $this->config['database'];
        $ignoreTables = array_map(function ($table) use ($db) {
            return $db.'.'.$table;
        }, $this->config['ignoreTables']);

        $commands = [];
        foreach ($ignoreTables as $ignoreTable) {
            $commands[] = sprintf(
                '--ignore-table=%s',
                escapeshellarg($ignoreTable)
            );
        }

        return implode(' ', $commands);
    }
}
