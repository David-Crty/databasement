<?php

namespace App\Services\Backup\DTO;

use App\Enums\SnapshotFileStatus;

readonly class BackupResult
{
    /**
     * @param  list<VolumeTransferResult>  $volumeResults  One outcome per target volume
     */
    public function __construct(
        public string $filename,
        public int $fileSize,
        public string $checksum,
        public array $volumeResults = [],
    ) {}

    public function hasFailures(): bool
    {
        return $this->failedResults() !== [];
    }

    /**
     * @return list<VolumeTransferResult>
     */
    public function failedResults(): array
    {
        return array_values(array_filter(
            $this->volumeResults,
            fn (VolumeTransferResult $result) => $result->status === SnapshotFileStatus::Failed,
        ));
    }

    /**
     * Notify-only storage limit overage messages collected across volumes.
     *
     * @return list<VolumeTransferResult>
     */
    public function storageWarnings(): array
    {
        return array_values(array_filter(
            $this->volumeResults,
            fn (VolumeTransferResult $result) => $result->storageWarning !== null,
        ));
    }
}
