<?php

namespace App\Services\Backup\DTO;

use App\Enums\CompressionType;
use App\Enums\DatabaseType;

readonly class RestoreConfig
{
    public function __construct(
        public DatabaseConnectionConfig $targetServer,
        public VolumeConfig $snapshotVolume,
        public string $snapshotFilename,
        public int $snapshotFileSize,
        public CompressionType $snapshotCompressionType,
        public DatabaseType $snapshotDatabaseType,
        public string $snapshotDatabaseName,
        public string $schemaName,
        public string $workingDirectory,
    ) {}
}
