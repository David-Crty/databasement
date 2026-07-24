<?php

namespace App\Exceptions\Backup;

use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\VolumeTransferResult;

/**
 * Thrown when uploading the backup archive failed for at least one target
 * volume. Carries the full per-volume outcome so callers can persist the
 * successful copies before failing the job.
 */
class VolumeTransferException extends BackupException
{
    public function __construct(public readonly BackupResult $result, string $message)
    {
        parent::__construct($message);
    }

    /**
     * True when every failed upload was blocked by a storage quota — a state
     * retrying cannot fix, so the job should fail without retry.
     */
    public function allFailuresAreQuota(): bool
    {
        $failures = $this->result->failedResults();

        return $failures !== []
            && array_all($failures, fn (VolumeTransferResult $failure) => $failure->quotaExceeded);
    }
}
