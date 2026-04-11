<?php

namespace App\Support;

use Carbon\Carbon;
use Lorisleiva\CronTranslator\CronTranslator;

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

    /**
     * Format bytes into human-readable file size
     */
    public static function humanFileSize(?int $bytes): string
    {
        if ($bytes === null || $bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Format a date/datetime into human-readable format
     * Output format: Dec 19, 2025, 16:44
     */
    public static function humanDate(\DateTimeInterface|Carbon|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        return $date->format('M j, Y, H:i');
    }

    /**
     * Translate a cron expression into human-readable text
     */
    public static function cronTranslation(string $expression, string $fallback = ''): string
    {
        try {
            return CronTranslator::translate($expression);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Replace {year}, {month} and {day} placeholders in a backup path with
     * zero-padded date parts. Used both to resolve the actual destination
     * folder at backup time and to show a live preview in the UI.
     */
    public static function resolveDatePlaceholders(string $path, ?\DateTimeInterface $date = null): string
    {
        $date ??= Carbon::now();

        return str_replace(
            ['{year}', '{month}', '{day}'],
            [$date->format('Y'), $date->format('m'), $date->format('d')],
            $path,
        );
    }
}
