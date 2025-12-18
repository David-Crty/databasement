<?php

namespace App\Support;

class Formatters
{
    /**
     * Format milliseconds into human-readable duration
     */
    public static function humanDuration(?int $ms): ?string
    {
        if ($ms === null) {
            return null;
        }

        if ($ms < 1000) {
            return "{$ms}ms";
        }

        $seconds = round($ms / 1000, 2);

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = round($seconds % 60, 2);

        return "{$minutes}m {$remainingSeconds}s";
    }
}
