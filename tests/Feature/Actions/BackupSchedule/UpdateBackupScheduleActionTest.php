<?php

use App\Actions\BackupSchedule\UpdateBackupScheduleAction;
use App\Models\BackupSchedule;

test('it renames a schedule and changes when it runs', function () {
    $schedule = BackupSchedule::factory()->create();

    UpdateBackupScheduleAction::run($schedule, 'Nightly', '0 2 * * *');

    expect($schedule->fresh())->name->toBe('Nightly')->expression->toBe('0 2 * * *');
});
