<?php

use App\Actions\BackupSchedule\DeleteBackupScheduleAction;
use App\Exceptions\Backup\BackupScheduleInUseException;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;

test('it deletes a schedule nothing uses', function () {
    $schedule = BackupSchedule::factory()->create();

    DeleteBackupScheduleAction::run($schedule);

    expect(BackupSchedule::find($schedule->id))->toBeNull();
});

test('a schedule backups still run on is kept', function () {
    $schedule = DatabaseServer::factory()->create()->backups->first()->backupSchedule;

    expect(fn () => DeleteBackupScheduleAction::run($schedule))
        ->toThrow(fn (BackupScheduleInUseException $e) => expect($e->backupsCount)->toBe(1)
            ->and($e->scheduledRestoresCount)->toBe(0));

    expect(BackupSchedule::find($schedule->id))->not->toBeNull();
});

test('a schedule a scheduled restore still runs on is kept, whichever organization owns it', function () {
    $schedule = BackupSchedule::factory()->create();
    [, $foreignTarget] = foreignServer();
    createScheduledRestore(['target' => $foreignTarget, 'backup_schedule_id' => $schedule->id]);

    expect(fn () => DeleteBackupScheduleAction::run($schedule))
        ->toThrow(fn (BackupScheduleInUseException $e) => expect($e->scheduledRestoresCount)->toBe(1));

    expect(BackupSchedule::find($schedule->id))->not->toBeNull();
});
