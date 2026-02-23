<?php

namespace App\Services\Backup\DTO;

use App\Enums\CompressionType;

readonly class BackupConfig
{
    public function __construct(
        public DatabaseConnectionConfig $database,
        public VolumeConfig $volume,
        public string $databaseName,
        public string $workingDirectory,
        public string $backupPath = '',
        public ?CompressionType $compressionType = null,
        public ?int $compressionLevel = null,
    ) {}
}
