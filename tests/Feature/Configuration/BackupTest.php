<?php

use App\Facades\AppConfig;
use App\Jobs\CleanupExpiredSnapshotsJob;
use App\Jobs\ProcessBackupJob;
use App\Jobs\VerifySnapshotFileJob;
use App\Livewire\Configuration\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Process::fake();
});

test('backup page displays current values', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($user)
        ->test(Backup::class)
        ->assertSee('Configuration')
        ->assertSee('Save Backup Settings')
        ->assertSet('form.compression', 'gzip')
        ->assertSet('form.compression_level', 6)
        ->assertSet('form.verify_files', true);
});

test('non-admin users see read-only backup page', function () {
    $user = User::factory()->create(['role' => 'member']);

    Livewire::actingAs($user)
        ->test(Backup::class)
        ->assertSee('Configuration')
        ->assertDontSee('Save Backup Settings');
});

test('non-admin users cannot save backup config', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Backup::class)
        ->call('saveBackupConfig')
        ->assertForbidden();
});

test('saving backup config persists values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
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

test('shows warning toast when scheduler restart fails', function () {
    Log::spy();

    Process::fake(fn () => Process::result(errorOutput: 'connection refused', exitCode: 1));

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->call('saveBackupConfig');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'Failed to restart schedule-run'))
        ->once();
});

test('validation rejects invalid backup values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->set('form.compression', 'invalid')
        ->set('form.compression_level', 0)
        ->set('form.job_timeout', 10)
        ->set('form.cleanup_cron', 'not a cron')
        ->call('saveBackupConfig')
        ->assertHasErrors(['form.compression', 'form.compression_level', 'form.job_timeout', 'form.cleanup_cron']);
});

// Backup Schedule CRUD tests

test('admin can create a backup schedule', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
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

test('admin can edit a backup schedule', function () {
    $schedule = BackupSchedule::factory()->create([
        'name' => 'Old Name',
        'expression' => '0 1 * * *',
    ]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
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

test('admin can delete an unused backup schedule', function () {
    $schedule = BackupSchedule::factory()->create(['name' => 'To Delete']);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
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

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->call('confirmDeleteSchedule', $schedule->id)
        ->call('deleteSchedule');

    $this->assertDatabaseHas('backup_schedules', ['id' => $schedule->id]);
});

test('schedule name must be unique', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->call('openScheduleModal')
        ->set('form.schedule_name', 'Daily')
        ->set('form.schedule_expression', '0 2 * * *')
        ->call('saveSchedule')
        ->assertHasErrors(['form.schedule_name']);
});

test('schedule requires valid cron expression', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->call('openScheduleModal')
        ->set('form.schedule_name', 'Bad Cron')
        ->set('form.schedule_expression', 'not valid')
        ->call('saveSchedule')
        ->assertHasErrors(['form.schedule_expression']);
});

test('non-admin cannot create schedule', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Backup::class)
        ->call('saveSchedule')
        ->assertForbidden();
});

test('non-admin cannot delete schedule', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Backup::class)
        ->call('deleteSchedule')
        ->assertForbidden();
});

test('admin can run a schedule to trigger backups for all its servers', function () {
    Queue::fake();

    $schedule = BackupSchedule::factory()->create();
    $server = DatabaseServer::factory()->create(['database_names' => ['app']]);
    $server->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->call('runSchedule', $schedule->id);

    Queue::assertPushed(ProcessBackupJob::class);
});

test('running a schedule skips servers with backups disabled', function () {
    Queue::fake();

    $schedule = BackupSchedule::factory()->create();
    $enabledServer = DatabaseServer::factory()->create(['database_names' => ['app'], 'backups_enabled' => true]);
    $disabledServer = DatabaseServer::factory()->create(['database_names' => ['app'], 'backups_enabled' => false]);
    $enabledServer->backups->first()->update(['backup_schedule_id' => $schedule->id]);
    $disabledServer->backups->first()->update(['backup_schedule_id' => $schedule->id]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->call('runSchedule', $schedule->id);

    Queue::assertPushed(ProcessBackupJob::class, function ($job) use ($enabledServer) {
        return Snapshot::find($job->snapshotId)->database_server_id === $enabledServer->id;
    });

    Queue::assertNotPushed(ProcessBackupJob::class, function ($job) use ($disabledServer) {
        return Snapshot::find($job->snapshotId)->database_server_id === $disabledServer->id;
    });
});

test('non-admin cannot run a schedule', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Backup::class)
        ->call('runSchedule', 'fake-id')
        ->assertForbidden();
});

test('admin can run cleanup manually', function () {
    Queue::fake();

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->call('runCleanup');

    Queue::assertPushed(CleanupExpiredSnapshotsJob::class);
});

test('non-admin cannot run cleanup', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Backup::class)
        ->call('runCleanup')
        ->assertForbidden();
});

test('admin can run verify files manually', function () {
    Queue::fake();

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Backup::class)
        ->call('runVerifyFiles');

    Queue::assertPushed(VerifySnapshotFileJob::class);
});

test('non-admin cannot run verify files', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Backup::class)
        ->call('runVerifyFiles')
        ->assertForbidden();
});
