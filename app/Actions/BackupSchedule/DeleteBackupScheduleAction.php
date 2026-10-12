<?php

namespace App\Actions\BackupSchedule;

use App\Exceptions\Backup\BackupScheduleInUseException;
use App\Models\BackupSchedule;
use App\Models\Scopes\DatabaseServerOrganizationScope;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteBackupScheduleAction
{
    use AsFake, AsObject;

    /**
     * Delete a schedule nothing runs on anymore.
     *
     * @throws BackupScheduleInUseException While backups or scheduled restores still use it
     */
    public function handle(BackupSchedule $schedule): void
    {
        $backupsCount = $schedule->backups()->count();
        // Schedules are shared by every organization, so their use is too.
        $scheduledRestoresCount = $schedule->scheduledRestores()->withoutGlobalScope(DatabaseServerOrganizationScope::class)->count();

        if ($backupsCount > 0 || $scheduledRestoresCount > 0) {
            throw new BackupScheduleInUseException($schedule, $backupsCount, $scheduledRestoresCount);
        }

        $schedule->delete();
    }
}
