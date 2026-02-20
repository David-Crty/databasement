<?php

namespace App\Services\Backup;

class BackupResult
{
    public function __construct(
        public readonly string $filename,
        public readonly int $fileSize,
        public readonly string $checksum,
    ) {}
}
