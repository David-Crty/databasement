<?php

namespace App\Services\Backup\Databases;

use App\Models\BackupJob;
use App\Services\Backup\Databases\DTO\DatabaseOperationResult;

interface DatabaseInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void;

    /**
     * Dump the database to the given output path.
     */
    public function dump(string $outputPath): DatabaseOperationResult;

    /**
     * Restore the database from the given input path.
     */
    public function restore(string $inputPath): DatabaseOperationResult;

    /**
     * Prepare the target database for restore (e.g. drop and recreate).
     */
    public function prepareForRestore(string $schemaName, BackupJob $job): void;

    /**
     * List available databases on the server.
     *
     * @return array<string>
     */
    public function listDatabases(): array;

    /**
     * Test the database connection.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function testConnection(): array;
}
