<?php

namespace App\Services\Backup\DTO;

readonly class BackupResult
{
    public function __construct(
        public string $filename,
        public int $fileSize,
        public string $checksum,
        // Set when the volume reached its storage limit but is in notify-only
        // mode: the backup was still uploaded and this message describes the
        // overage so the job can notify the user. Null when within the limit.
        public ?string $storageWarning = null,
    ) {}
}
