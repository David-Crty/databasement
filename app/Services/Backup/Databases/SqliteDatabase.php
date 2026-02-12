<?php

namespace App\Services\Backup\Databases;

use App\Models\BackupJob;

class SqliteDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getDumpCommandLine(string $outputPath): string
    {
        return sprintf('cp %s %s', escapeshellarg($this->config['sqlite_path']), escapeshellarg($outputPath));
    }

    public function getRestoreCommandLine(string $inputPath): string
    {
        return sprintf(
            'cp %s %s && chmod 0640 %s',
            escapeshellarg($inputPath),
            escapeshellarg($this->config['sqlite_path']),
            escapeshellarg($this->config['sqlite_path'])
        );
    }

    public function prepareForRestore(string $schemaName, BackupJob $job): void
    {
        // SQLite doesn't need database preparation — the file is replaced during restore
    }

    public function testConnection(): array
    {
        $path = $this->config['sqlite_path'] ?? '';

        if (empty($path)) {
            return ['success' => false, 'message' => 'Database path is required.', 'details' => []];
        }

        if (! file_exists($path)) {
            return ['success' => false, 'message' => 'Database file does not exist: '.$path, 'details' => []];
        }

        if (! is_readable($path)) {
            return ['success' => false, 'message' => 'Database file is not readable: '.$path, 'details' => []];
        }

        if (! is_file($path)) {
            return ['success' => false, 'message' => 'Path is not a file: '.$path, 'details' => []];
        }

        try {
            $pdo = new \PDO("sqlite:{$path}", null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->query('SELECT 1 FROM sqlite_master LIMIT 1');

            $stmt = $pdo->query('SELECT sqlite_version()');
            $version = $stmt ? $stmt->fetchColumn() : 'unknown';

            $fileSize = filesize($path);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'output' => json_encode(['dbms' => "SQLite {$version}", 'file_size' => $fileSize, 'path' => $path], JSON_PRETTY_PRINT),
                ],
            ];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Invalid SQLite database file: '.$e->getMessage(), 'details' => []];
        }
    }
}
