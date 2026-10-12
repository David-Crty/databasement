<?php

namespace App\Actions\BackupSchedule;

use App\Models\BackupSchedule;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateBackupScheduleAction
{
    use AsFake, AsObject;

    public function handle(string $name, string $expression): BackupSchedule
    {
        return BackupSchedule::create([
            'name' => $name,
            'expression' => $expression,
        ]);
    }
}
