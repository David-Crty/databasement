<?php

namespace App\Enums;

enum RestoreModalMode: string
{
    case FromServer = 'from-server';
    case FromSnapshot = 'from-snapshot';
    case FromRestoreIndex = 'from-restore-index';

    public function totalSteps(): int
    {
        return match ($this) {
            // Snapshot picker, then the merged target + destination step.
            self::FromServer, self::FromRestoreIndex => 2,
            // Snapshot is fixed, so the target + destination step is all there is.
            self::FromSnapshot => 1,
        };
    }

    public function targetServerLocked(): bool
    {
        return $this === self::FromServer;
    }

    public function snapshotLocked(): bool
    {
        return $this === self::FromSnapshot;
    }
}
