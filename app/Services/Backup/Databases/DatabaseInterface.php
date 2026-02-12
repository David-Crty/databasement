<?php

namespace App\Services\Backup\Databases;

use App\Models\BackupJob;

interface DatabaseInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void;

    public function getDumpCommandLine(string $outputPath): string;

    public function getRestoreCommandLine(string $inputPath): string;

    /**
     * Prepare the target database for restore (e.g. drop and recreate).
     */
    public function prepareForRestore(string $schemaName, BackupJob $job): void;

    /**
     * Test the database connection.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function testConnection(): array;
}
