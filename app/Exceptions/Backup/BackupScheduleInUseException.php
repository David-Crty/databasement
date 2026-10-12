<?php

namespace App\Exceptions\Backup;

use App\Models\BackupSchedule;
use Exception;

/**
 * A backup schedule still drives backups or scheduled restores, so it cannot
 * be deleted.
 */
class BackupScheduleInUseException extends Exception
{
    public function __construct(
        public readonly BackupSchedule $schedule,
        public readonly int $backupsCount,
        public readonly int $scheduledRestoresCount,
    ) {
        $users = $backupsCount > 0 ? 'database servers' : 'scheduled restores';

        parent::__construct("Cannot delete a schedule that is in use by {$users}.");
    }
}
