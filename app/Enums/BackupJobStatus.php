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
}
