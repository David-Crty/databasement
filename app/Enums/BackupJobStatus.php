<?php

namespace App\Enums;

enum BackupJobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isInProgress(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }

    /**
     * Options for the job status filters of the listing pages.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function filterOptions(): array
    {
        return [
            ['id' => self::Completed->value, 'name' => __('Completed')],
            ['id' => self::Failed->value, 'name' => __('Failed')],
            ['id' => self::Running->value, 'name' => __('Running')],
            ['id' => self::Pending->value, 'name' => __('Pending')],
            ['id' => self::Cancelled->value, 'name' => __('Cancelled')],
        ];
    }
}
