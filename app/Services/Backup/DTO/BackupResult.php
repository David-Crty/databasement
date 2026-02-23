<?php

namespace App\Services\Backup\DTO;

readonly class BackupResult
{
    public function __construct(
        public string $filename,
        public int $fileSize,
        public string $checksum,
    ) {}
}
