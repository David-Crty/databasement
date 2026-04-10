<?php

use App\Facades\AppConfig;
use App\Jobs\CleanupExpiredSnapshotsJob;
use App\Jobs\ProcessBackupJob;
use App\Jobs\VerifySnapshotFileJob;
use App\Livewire\Configuration\Index;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Snapshot;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Process::fake();
});

test('configuration page displays current values', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Configuration')
        ->assertSee('Save Backup Settings')
        ->assertSet('form.compression', 'gzip')
        ->assertSet('form.compression_level', 6)
        ->assertSet('form.verify_files', true);
});

test('non-admin users see read-only configuration page', function () {
    $user = User::factory()->create(['role' => 'member']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Configuration')
        ->assertDontSee('Save Backup Settings');
});

test('non-admin users cannot save backup config', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('saveBackupConfig')
        ->assertForbidden();
});

test('non-admin users cannot send test notification', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('sendTestNotification', 'fake-id')
        ->assertForbidden();
});

test('saving backup config persists values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
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
        ->test(Index::class)
        ->call('saveBackupConfig');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'Failed to restart schedule-run'))
        ->once();
});

test('validation rejects invalid backup values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.compression', 'invalid')
        ->set('form.compression_level', 0)
        ->set('form.job_timeout', 10)
        ->set('form.cleanup_cron', 'not a cron')
        ->call('saveBackupConfig')
        ->assertHasErrors(['form.compression', 'form.compression_level', 'form.job_timeout', 'form.cleanup_cron']);
});

// Notification Channel CRUD tests

test('admin can create a notification channel', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('openChannelModal')
        ->assertSet('showChannelModal', true)
        ->set('channelForm.name', 'Admin Email')
        ->set('channelForm.type', 'email')
        ->set('channelForm.config_to', 'admin@example.com')
        ->call('saveChannel')
        ->assertHasNoErrors()
        ->assertSet('showChannelModal', false);

    $this->assertDatabaseHas('notification_channels', [
        'name' => 'Admin Email',
        'type' => 'email',
    ]);
});

test('admin can edit a notification channel', function () {
    $channel = NotificationChannel::factory()->email()->create([
        'name' => 'Old Name',
        'config' => ['to' => 'old@example.com'],
    ]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('openChannelModal', $channel->id)
        ->assertSet('channelForm.name', 'Old Name')
        ->assertSet('channelForm.config_to', 'old@example.com')
        ->set('channelForm.name', 'Updated Name')
        ->set('channelForm.config_to', 'new@example.com')
        ->call('saveChannel')
        ->assertHasNoErrors();

    expect($channel->fresh()->name)->toBe('Updated Name');
});

test('admin can delete a notification channel', function () {
    $channel = NotificationChannel::factory()->email()->create(['name' => 'To Delete']);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('confirmDeleteChannel', $channel->id)
        ->assertSet('showDeleteChannelModal', true)
        ->call('deleteChannel')
        ->assertSet('showDeleteChannelModal', false);

    $this->assertDatabaseMissing('notification_channels', ['id' => $channel->id]);
});

test('non-admin cannot save notification channel', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('saveChannel')
        ->assertForbidden();
});

test('non-admin cannot delete notification channel', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('deleteChannel')
        ->assertForbidden();
});

test('sendTestNotification sends notification for a channel', function () {
    $channel = NotificationChannel::factory()->email()->create([
        'name' => 'Test Email',
        'config' => ['to' => 'admin@example.com'],
    ]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('sendTestNotification', $channel->id);

    Notification::assertSentTimes(BackupFailedNotification::class, 1);
});

test('sendTestNotification handles notification failure gracefully', function () {
    $channel = NotificationChannel::factory()->email()->create([
        'name' => 'Broken Email',
        'config' => ['to' => 'admin@example.com'],
    ]);

    $mock = Mockery::mock(NotificationService::class);
    $mock->shouldReceive('sendTestNotification')->andThrow(new RuntimeException('SMTP connection failed'));
    app()->instance(NotificationService::class, $mock);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('sendTestNotification', $channel->id)
        ->assertSuccessful();
});

test('admin can create notification channels of various types', function (string $type, array $formFields, array $expectedOnEdit) {
    $component = Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('openChannelModal')
        ->set('channelForm.name', 'Test Channel')
        ->set('channelForm.type', $type);

    foreach ($formFields as $field => $value) {
        $component->set("channelForm.{$field}", $value);
    }

    $component->call('saveChannel')
        ->assertHasNoErrors()
        ->assertSet('showChannelModal', false);

    $channel = NotificationChannel::where('name', 'Test Channel')->where('type', $type)->firstOrFail();

    // Re-open the modal to exercise setChannel() for this type
    $component->call('openChannelModal', $channel->id)
        ->assertSet('channelForm.name', 'Test Channel')
        ->assertSet('channelForm.type', $type);

    foreach ($expectedOnEdit as $prop => $value) {
        $component->assertSet("channelForm.{$prop}", $value);
    }
})->with([
    'slack' => ['slack', ['config_webhook_url' => 'https://hooks.slack.com/services/test'], ['has_config_webhook_url' => true]],
    'discord' => ['discord', ['config_token' => 'bot-token', 'config_channel_id' => '123456'], ['has_config_token' => true, 'config_channel_id' => '123456']],
    'discord_webhook' => ['discord_webhook', ['config_url' => 'https://discord.com/api/webhooks/123/abc'], ['has_config_url' => true]],
    'telegram' => ['telegram', ['config_bot_token' => 'bot-token', 'config_chat_id' => '-123456'], ['has_config_bot_token' => true, 'config_chat_id' => '-123456']],
    'pushover' => ['pushover', ['config_token' => 'app-token', 'config_user_key' => 'user-key'], ['has_config_token' => true, 'has_config_user_key' => true]],
    'gotify' => ['gotify', ['config_url' => 'https://gotify.example.com', 'config_token' => 'app-token'], ['config_url' => 'https://gotify.example.com', 'has_config_token' => true]],
    'webhook' => ['webhook', ['config_url' => 'https://webhook.example.com/notify'], ['config_url' => 'https://webhook.example.com/notify', 'has_config_secret' => false]],
]);

test('editing a channel preserves sensitive fields when left blank', function () {
    $channel = NotificationChannel::factory()->slack()->create([
        'name' => 'Slack Alerts',
        'config' => ['webhook_url' => \Illuminate\Support\Facades\Crypt::encryptString('https://hooks.slack.com/original')],
    ]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('openChannelModal', $channel->id)
        ->assertSet('channelForm.has_config_webhook_url', true)
        ->set('channelForm.name', 'Updated Slack')
        ->set('channelForm.config_webhook_url', '') // Leave blank to keep existing
        ->call('saveChannel')
        ->assertHasNoErrors();

    $updated = $channel->fresh();
    expect($updated->name)->toBe('Updated Slack')
        ->and($updated->config['webhook_url'])->not->toBeEmpty();
});

// Backup Schedule CRUD tests

test('admin can create a backup schedule', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
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
        ->test(Index::class)
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
        ->test(Index::class)
        ->call('confirmDeleteSchedule', $schedule->id)
        ->assertSet('showDeleteScheduleModal', true)
        ->call('deleteSchedule')
        ->assertSet('showDeleteScheduleModal', false);

    $this->assertDatabaseMissing('backup_schedules', ['id' => $schedule->id]);
});

test('cannot delete a backup schedule that is in use', function () {
    $server = DatabaseServer::factory()->create();
    $schedule = $server->backup->backupSchedule;

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('confirmDeleteSchedule', $schedule->id)
        ->call('deleteSchedule');

    $this->assertDatabaseHas('backup_schedules', ['id' => $schedule->id]);
});

test('schedule name must be unique', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('openScheduleModal')
        ->set('form.schedule_name', 'Daily')
        ->set('form.schedule_expression', '0 2 * * *')
        ->call('saveSchedule')
        ->assertHasErrors(['form.schedule_name']);
});

test('schedule requires valid cron expression', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('openScheduleModal')
        ->set('form.schedule_name', 'Bad Cron')
        ->set('form.schedule_expression', 'not valid')
        ->call('saveSchedule')
        ->assertHasErrors(['form.schedule_expression']);
});

test('non-admin cannot create schedule', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('saveSchedule')
        ->assertForbidden();
});

test('non-admin cannot delete schedule', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('deleteSchedule')
        ->assertForbidden();
});

test('admin can run a schedule to trigger backups for all its servers', function () {
    Queue::fake();

    $schedule = BackupSchedule::factory()->create();
    $server = DatabaseServer::factory()->create(['database_names' => ['app']]);
    $server->backup->update(['backup_schedule_id' => $schedule->id]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('runSchedule', $schedule->id);

    Queue::assertPushed(ProcessBackupJob::class);
});

test('running a schedule skips servers with backups disabled', function () {
    Queue::fake();

    $schedule = BackupSchedule::factory()->create();
    $enabledServer = DatabaseServer::factory()->create(['database_names' => ['app'], 'backups_enabled' => true]);
    $disabledServer = DatabaseServer::factory()->create(['database_names' => ['app'], 'backups_enabled' => false]);
    $enabledServer->backup->update(['backup_schedule_id' => $schedule->id]);
    $disabledServer->backup->update(['backup_schedule_id' => $schedule->id]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
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
        ->test(Index::class)
        ->call('runSchedule', 'fake-id')
        ->assertForbidden();
});

test('admin can run cleanup manually', function () {
    Queue::fake();

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('runCleanup');

    Queue::assertPushed(CleanupExpiredSnapshotsJob::class);
});

test('non-admin cannot run cleanup', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('runCleanup')
        ->assertForbidden();
});

test('admin can run verify files manually', function () {
    Queue::fake();

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('runVerifyFiles');

    Queue::assertPushed(VerifySnapshotFileJob::class);
});

test('non-admin cannot run verify files', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('runVerifyFiles')
        ->assertForbidden();
});
