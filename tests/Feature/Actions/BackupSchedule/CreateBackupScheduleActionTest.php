<?php

use App\Actions\BackupSchedule\CreateBackupScheduleAction;

test('it creates a schedule', function () {
    $schedule = CreateBackupScheduleAction::run('Hourly', '0 * * * *');

    expect($schedule->fresh())->name->toBe('Hourly')->expression->toBe('0 * * * *');
});
