<?php

namespace App\Enums;

enum RunKind: string
{
    case FULL = 'full';
    case INCREMENTAL = 'incremental';

    /**
     * Human-readable label for the run kind, used across the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::FULL => 'Full',
            self::INCREMENTAL => 'Incremental',
        };
    }

    /**
     * Whether a run of this kind archives every object in scope (rather than
     * only those that changed since the preceding archive).
     */
    public function isFull(): bool
    {
        return $this === self::FULL;
    }
}
