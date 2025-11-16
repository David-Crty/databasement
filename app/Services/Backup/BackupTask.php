<?php

namespace App\Services\Backup;

use App\Models\DatabaseServer;
use App\Services\Backup\Databases\MysqlDatabaseInterface;
use App\Services\Backup\Databases\PostgresqlDatabaseInterface;
use App\Services\Backup\Filesystems\FilesystemProvider;
use League\Flysystem\Filesystem;
use Symfony\Component\Process\Process;

class BackupTask
{
    public function __construct(
        private readonly MysqlDatabaseInterface $mysqlDatabase,
        private readonly PostgresqlDatabaseInterface $postgresqlDatabase,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly GzipCompressor $compressor
    ) {}

    protected function getWorkingFile($name, $filename = null): string
    {
        if (is_null($filename)) {
            $filename = uniqid();
        }

        return sprintf('%s/%s', $this->getRootPath($name), $filename);
    }

    protected function getRootPath($name): string
    {
        $path = $this->filesystemProvider->getConfig($name, 'root');

        return preg_replace('/\/$/', '', $path);
    }

    public function run(DatabaseServer $databaseServer)
    {
        $workingFile = $this->getWorkingFile('local');
        $filesystem = $this->filesystemProvider->get($databaseServer->backup->volume->type);

        // Configure database interface with server credentials
        $this->configureDatabaseInterface($databaseServer);

        try {
            $this->dumpDatabase($databaseServer, $workingFile);
            $archive = $this->compress($workingFile);
            $this->transfer($databaseServer, $archive, $filesystem);
        } finally {
            // Clean up temporary files
            if (file_exists($workingFile)) {
                unlink($workingFile);
            }
            if (isset($archive) && file_exists($archive)) {
                unlink($archive);
            }
        }
    }

    private function dumpDatabase(DatabaseServer $databaseServer, string $outputPath): void
    {
        switch ($databaseServer->database_type) {
            case 'mysql':
            case 'mariadb':
                $this->shellProcessor->process(
                    Process::fromShellCommandline(
                        $this->mysqlDatabase->getDumpCommandLine($outputPath)
                    )
                );
                break;
            case 'postgresql':
                $this->shellProcessor->process(
                    Process::fromShellCommandline(
                        $this->postgresqlDatabase->getDumpCommandLine($outputPath)
                    )
                );
                break;
            default:
                throw new \Exception("Database type {$databaseServer->database_type} not supported");
        }
    }

    private function compress(string $path): string
    {
        $this->shellProcessor->process(
            Process::fromShellCommandline(
                $this->compressor->getCompressCommandLine($path)
            )
        );

        return $this->compressor->getCompressedPath($path);
    }

    private function transfer(DatabaseServer $databaseServer, string $path, Filesystem $filesystem): void
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open file: {$path}");
        }

        try {
            $destinationPath = $this->generateBackupFilename($databaseServer);
            $filesystem->writeStream($destinationPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function generateBackupFilename(DatabaseServer $databaseServer): string
    {
        $timestamp = now()->format('Y-m-d-His');
        $serverName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseServer->name);
        $databaseName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseServer->database_name ?? 'db');

        return sprintf('%s-%s-%s.sql.gz', $serverName, $databaseName, $timestamp);
    }

    private function configureDatabaseInterface(DatabaseServer $databaseServer): void
    {
        $config = [
            'host' => $databaseServer->host,
            'port' => $databaseServer->port,
            'user' => $databaseServer->username,
            'pass' => $databaseServer->password,
            'database' => $databaseServer->database_name,
        ];

        match ($databaseServer->database_type) {
            'mysql', 'mariadb' => $this->mysqlDatabase->setConfig($config),
            'postgresql' => $this->postgresqlDatabase->setConfig($config),
            default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
        };
    }
}
