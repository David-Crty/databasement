<?php

namespace App\Services\Backup\DTO;

use App\Models\Restore;

readonly class ScheduledRestoreResult
{
    private function __construct(
        public ?Restore $restore,
        public ?string $skipReason,
    ) {}

    public static function dispatched(Restore $restore): self
    {
        return new self($restore, null);
    }

    /**
     * @param  string  $reason  One of the ScheduledRestore::SKIP_* constants
     */
    public static function skipped(string $reason): self
    {
        return new self(null, $reason);
    }
}
