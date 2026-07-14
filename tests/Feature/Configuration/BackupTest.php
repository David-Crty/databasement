<?php

use App\Enums\Ability;
use App\Facades\AppConfig;
use App\Jobs\CleanupExpiredSnapshotsJob;
use App\Jobs\VerifySnapshotFileJob;
use App\Livewire\Configuration\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('backup page displays current values', function () {
    $user = User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create();

    Livewire::actingAs($user)
        ->test(Backup::class)
        ->assertSee('Configuration')
        ->assertSee('Save Backup Settings')
        ->assertSet('form.compression', 'gzip')
        ->assertSet('form.compression_level', 6)
        ->assertSet('form.verify_files', true);
});

test('without manage-backup-settings, the page is viewable but saving is forbidden', function () {
    Livewire::actingAs(User::factory()->withAllAbilitiesExcept(Ability::ManageBackupSettings->value)->create())
        ->test(Backup::class)
        ->assertOk()
        ->call('saveBackupConfig')
        ->assertForbidden();
});

test('saving backup config persists values', function () {
    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->set('form.compression', 'zstd')
        ->set('form.compression_level', 10)
        ->set('form.job_timeout', 3600)
        ->call('saveBackupConfig')
        ->assertHasNoErrors();

    expect(AppConfig::get('backup.compression'))->toBe('zstd')
        ->and(AppConfig::get('backup.compression_level'))->toBe(10)
        ->and(AppConfig::get('backup.job_timeout'))->toBe(3600);
});

test('saving backup config persists multithreading toggle', function () {
    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->set('form.compression', 'zstd')
        ->set('form.compression_multithread', true)
        ->call('saveBackupConfig')
        ->assertHasNoErrors();

    expect(AppConfig::get('backup.compression_multithread'))->toBeTrue();
});

test('switching to gzip clamps the compression level to its ceiling', function () {
    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->set('form.compression', 'zstd')
        ->set('form.compression_level', 19)
        ->set('form.compression', 'gzip')
        ->assertSet('form.compression_level', 9);
});

test('saving backup config persists post-backup and post-restore scripts', function () {
    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->set('form.post_backup_script', 'echo "$BACKUP_FILENAME"')
        ->set('form.post_restore_script', 'echo "$RESTORE_DATABASE_NAME"')
        ->call('saveBackupConfig')
        ->assertHasNoErrors();

    expect(AppConfig::get('backup.post_backup_script'))->toBe('echo "$BACKUP_FILENAME"')
        ->and(AppConfig::get('backup.post_restore_script'))->toBe('echo "$RESTORE_DATABASE_NAME"');
});

test('validation rejects invalid backup values', function () {
    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->set('form.compression', 'invalid')
        ->set('form.compression_level', 0)
        ->set('form.job_timeout', 10)
        ->set('form.cleanup_cron', 'not a cron')
        ->call('saveBackupConfig')
        ->assertHasErrors(['form.compression', 'form.compression_level', 'form.job_timeout', 'form.cleanup_cron']);
});

// Backup Schedule CRUD tests

test('manage-backup-settings allows creating a backup schedule', function () {
    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('openScheduleModal')
        ->assertSet('showScheduleModal', true)
        ->set('form.schedule_name', 'Every 3 Hours')
        ->set('form.schedule_expression', '0 */3 * * *')
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSet('showScheduleModal', false);

    $this->assertDatabaseHas('backup_schedules', [
        'name' => 'Every 3 Hours',
        'expression' => '0 */3 * * *',
    ]);
});

test('manage-backup-settings allows editing a backup schedule', function () {
    $schedule = BackupSchedule::factory()->create([
        'name' => 'Old Name',
        'expression' => '0 1 * * *',
    ]);

    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('openScheduleModal', $schedule->id)
        ->assertSet('form.schedule_name', 'Old Name')
        ->assertSet('form.schedule_expression', '0 1 * * *')
        ->set('form.schedule_name', 'Updated Name')
        ->set('form.schedule_expression', '0 6 * * *')
        ->call('saveSchedule')
        ->assertHasNoErrors();

    expect($schedule->fresh()->name)->toBe('Updated Name')
        ->and($schedule->fresh()->expression)->toBe('0 6 * * *');
});

test('manage-backup-settings allows deleting an unused backup schedule', function () {
    $schedule = BackupSchedule::factory()->create(['name' => 'To Delete']);

    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('confirmDeleteSchedule', $schedule->id)
        ->assertSet('showDeleteScheduleModal', true)
        ->call('deleteSchedule')
        ->assertSet('showDeleteScheduleModal', false);

    $this->assertDatabaseMissing('backup_schedules', ['id' => $schedule->id]);
});

test('cannot delete a backup schedule that is in use', function () {
    $server = DatabaseServer::factory()->create();
    $schedule = $server->backups->first()->backupSchedule;

    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('confirmDeleteSchedule', $schedule->id)
        ->call('deleteSchedule');

    $this->assertDatabaseHas('backup_schedules', ['id' => $schedule->id]);
});

test('cannot delete a backup schedule that is referenced by a scheduled restore', function () {
    $schedule = BackupSchedule::factory()->create();
    ScheduledRestore::factory()->create(['backup_schedule_id' => $schedule->id]);

    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('confirmDeleteSchedule', $schedule->id)
        ->call('deleteSchedule');

    $this->assertDatabaseHas('backup_schedules', ['id' => $schedule->id]);
});

test('schedule name must be unique', function () {
    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('openScheduleModal')
        ->set('form.schedule_name', 'Daily')
        ->set('form.schedule_expression', '0 2 * * *')
        ->call('saveSchedule')
        ->assertHasErrors(['form.schedule_name']);
});

test('schedule requires valid cron expression', function () {
    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('openScheduleModal')
        ->set('form.schedule_name', 'Bad Cron')
        ->set('form.schedule_expression', 'not valid')
        ->call('saveSchedule')
        ->assertHasErrors(['form.schedule_expression']);
});

test('without manage-backup-settings, creating a schedule is forbidden', function () {
    Livewire::actingAs(User::factory()->withAllAbilitiesExcept(Ability::ManageBackupSettings->value)->create())
        ->test(Backup::class)
        ->set('form.schedule_name', 'Every 3 Hours')
        ->set('form.schedule_expression', '0 */3 * * *')
        ->call('saveSchedule')
        ->assertForbidden();
});

test('without manage-backup-settings, deleting a schedule is forbidden', function () {
    $schedule = BackupSchedule::factory()->create();

    Livewire::actingAs(User::factory()->withAllAbilitiesExcept(Ability::ManageBackupSettings->value)->create())
        ->test(Backup::class)
        ->call('confirmDeleteSchedule', $schedule->id)
        ->call('deleteSchedule')
        ->assertForbidden();
});

test('manage-backup-settings allows running cleanup manually', function () {
    Queue::fake();

    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('runCleanup');

    Queue::assertPushed(CleanupExpiredSnapshotsJob::class);
});

test('without manage-backup-settings, running cleanup is forbidden', function () {
    Livewire::actingAs(User::factory()->withAllAbilitiesExcept(Ability::ManageBackupSettings->value)->create())
        ->test(Backup::class)
        ->call('runCleanup')
        ->assertForbidden();
});

test('manage-backup-settings allows running verify files manually', function () {
    Queue::fake();

    Livewire::actingAs(User::factory()->withAbilities([Ability::ManageBackupSettings->value])->create())
        ->test(Backup::class)
        ->call('runVerifyFiles');

    Queue::assertPushed(VerifySnapshotFileJob::class);
});

test('without manage-backup-settings, running verify files is forbidden', function () {
    Livewire::actingAs(User::factory()->withAllAbilitiesExcept(Ability::ManageBackupSettings->value)->create())
        ->test(Backup::class)
        ->call('runVerifyFiles')
        ->assertForbidden();
});
