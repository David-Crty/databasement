<?php

namespace App\Services\Backup\DTO;

use App\Enums\SnapshotFileStatus;

/**
 * Outcome of uploading one backup archive to one target volume.
 */
readonly class VolumeTransferResult
{
    public function __construct(
        public ?string $volumeId,
        public string $volumeName,
        public SnapshotFileStatus $status,
        public ?string $error = null,
        // Set when the volume reached its storage limit but is in notify-only
        // mode: the file was still uploaded and this message describes the
        // overage so the job can notify the user.
        public ?string $storageWarning = null,
        // True when the upload was skipped because it would exceed the
        // volume's storage limit (block mode) — a failure retrying can't fix.
        public bool $quotaExceeded = false,
    ) {}

    /**
     * @return array{volume_id: string|null, volume_name: string, status: string, error: string|null, storage_warning: string|null, quota_exceeded: bool}
     */
    public function toPayload(): array
    {
        return [
            'volume_id' => $this->volumeId,
            'volume_name' => $this->volumeName,
            'status' => $this->status->value,
            'error' => $this->error,
            'storage_warning' => $this->storageWarning,
            'quota_exceeded' => $this->quotaExceeded,
        ];
    }

    /**
     * @param  array{volume_id?: string|null, volume_name?: string, status: string, error?: string|null, storage_warning?: string|null, quota_exceeded?: bool}  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            volumeId: $payload['volume_id'] ?? null,
            volumeName: $payload['volume_name'] ?? '',
            status: SnapshotFileStatus::from($payload['status']),
            error: $payload['error'] ?? null,
            storageWarning: $payload['storage_warning'] ?? null,
            quotaExceeded: (bool) ($payload['quota_exceeded'] ?? false),
        );
    }
}
