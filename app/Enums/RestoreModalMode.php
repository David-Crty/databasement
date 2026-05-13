<?php

namespace App\Enums;

enum RestoreModalMode: string
{
    case FromServer = 'from-server';
    case FromSnapshot = 'from-snapshot';
    case FromRestoreIndex = 'from-restore-index';

    public function totalSteps(): int
    {
        return $this === self::FromRestoreIndex ? 3 : 2;
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
