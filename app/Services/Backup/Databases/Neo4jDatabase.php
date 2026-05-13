<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Services\Backup\DTO\DatabaseOperationResult;

class Neo4jDatabase implements DatabaseInterface
{
    /**
     * @var array<string, mixed>
     *
     * @phpstan-ignore property.onlyWritten
     */
    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        throw new \RuntimeException('Not implemented yet');
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        throw new \RuntimeException('Not implemented yet');
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * @return array<string>
     */
    public function listDatabases(): array
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function testConnection(): array
    {
        throw new \RuntimeException('Not implemented yet');
    }
}
