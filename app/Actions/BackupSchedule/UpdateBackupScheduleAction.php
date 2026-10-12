<?php

namespace App\Actions\BackupSchedule;

use App\Models\BackupSchedule;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateBackupScheduleAction
{
    use AsFake, AsObject;

    public function handle(BackupSchedule $schedule, string $name, string $expression): BackupSchedule
    {
        $schedule->update([
            'name' => $name,
            'expression' => $expression,
        ]);

        return $schedule;
    }
}
