<?php

use App\Facades\AppConfig;
use App\Livewire\Configuration\Index;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use App\Services\FailureNotificationService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('configuration page displays current values', function () {
    $user = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Configuration')
        ->assertSee('Save Backup Settings')
        ->assertSee('Save Notification Settings')
        ->assertSet('form.compression', 'gzip')
        ->assertSet('form.compression_level', 6)
        ->assertSet('form.verify_files', true)
        ->assertSet('form.notifications_enabled', false);
});

test('non-admin users see read-only configuration page', function () {
    $user = User::factory()->create(['role' => 'member']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Configuration')
        ->assertDontSee('Save Backup Settings')
        ->assertDontSee('Save Notification Settings');
});

test('non-admin users cannot save backup config', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('saveBackupConfig')
        ->assertForbidden();
});

test('non-admin users cannot save notification config', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('saveNotificationConfig')
        ->assertForbidden();
});

test('non-admin users cannot send test notification', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Index::class)
        ->call('sendTestNotification')
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

test('saving notification config persists values for selected channels', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.notifications_enabled', true)
        ->set('form.channels', ['email', 'discord'])
        ->set('form.mail_to', 'test@example.com')
        ->set('form.discord_token', 'bot-token-123')
        ->set('form.discord_channel_id', '123456789')
        ->call('saveNotificationConfig')
        ->assertHasNoErrors();

    expect(AppConfig::get('notifications.enabled'))->toBeTrue()
        ->and(AppConfig::get('notifications.mail.to'))->toBe('test@example.com')
        ->and(AppConfig::get('notifications.discord.channel_id'))->toBe('123456789');
});

test('deselecting a channel nulls its values on save', function () {
    AppConfig::set('notifications.mail.to', 'old@example.com');
    AppConfig::set('notifications.slack.webhook_url', 'https://hooks.slack.com/old');
    AppConfig::flush();

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', [])
        ->call('saveNotificationConfig')
        ->assertHasNoErrors();

    expect(AppConfig::get('notifications.mail.to'))->toBeNull()
        ->and(AppConfig::get('notifications.slack.webhook_url'))->toBeNull();
});

test('validation rejects invalid backup values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.compression', 'invalid')
        ->set('form.compression_level', 0)
        ->set('form.job_timeout', 10)
        ->set('form.daily_cron', 'not a cron')
        ->call('saveBackupConfig')
        ->assertHasErrors(['form.compression', 'form.compression_level', 'form.job_timeout', 'form.daily_cron']);
});

test('validation rejects invalid notification values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', ['email'])
        ->set('form.mail_to', 'not-an-email')
        ->call('saveNotificationConfig')
        ->assertHasErrors(['form.mail_to']);
});

test('selected channels require their fields', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', ['email', 'slack', 'discord'])
        ->set('form.mail_to', '')
        ->set('form.slack_webhook_url', '')
        ->set('form.discord_token', '')
        ->set('form.discord_channel_id', '')
        ->call('saveNotificationConfig')
        ->assertHasErrors(['form.mail_to', 'form.slack_webhook_url', 'form.discord_token', 'form.discord_channel_id']);
});

test('saving notifications requires at least one channel when enabled', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.notifications_enabled', true)
        ->set('form.channels', [])
        ->call('saveNotificationConfig')
        ->assertHasErrors(['form.channels']);
});

test('sendTestNotification sends notification when enabled', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('sendTestNotification');

    Notification::assertSentOnDemand(
        BackupFailedNotification::class,
        fn ($notification) => $notification->snapshot->databaseServer->name === '[TEST] Production Database'
            && str_contains($notification->exception->getMessage(), 'This is a test notification')
    );
});

test('sendTestNotification does not send when notifications disabled', function () {
    AppConfig::set('notifications.enabled', false);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('sendTestNotification');

    Notification::assertNothingSent();
});

test('sendTestNotification shows error when no channels configured', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', null);
    AppConfig::set('notifications.slack.webhook_url', null);
    AppConfig::set('notifications.discord.channel_id', null);

    $component = Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('sendTestNotification');

    Notification::assertNothingSent();

    $js = collect($component->effects['xjs'] ?? [])->pluck('expression')->implode(' ');
    expect($js)->toContain('No notification channels configured');
});

test('sendTestNotification handles notification failure gracefully', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $mock = Mockery::mock(FailureNotificationService::class);
    $mock->shouldReceive('getNotificationRoutes')->andReturn(['mail' => 'admin@example.com']);
    $mock->shouldReceive('notifyBackupFailed')->andThrow(new \RuntimeException('SMTP connection failed'));
    app()->instance(FailureNotificationService::class, $mock);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->call('sendTestNotification')
        ->assertSuccessful();
});

test('saving slack webhook persists and clears form field', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', ['slack'])
        ->set('form.slack_webhook_url', 'https://hooks.slack.com/services/new')
        ->call('saveNotificationConfig')
        ->assertHasNoErrors()
        ->assertSet('form.has_slack_webhook_url', true)
        ->assertSet('form.slack_webhook_url', '');

    expect(AppConfig::get('notifications.slack.webhook_url'))->toBe('https://hooks.slack.com/services/new');
});

test('form pre-selects discord channel when discord config exists', function () {
    AppConfig::set('notifications.discord.token', 'bot-token');
    AppConfig::set('notifications.discord.channel_id', '123456');
    AppConfig::flush();

    $component = Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->assertSet('form.has_discord_token', true)
        ->assertSet('form.discord_channel_id', '123456')
        ->assertSee('Configuration');

    expect($component->get('form.channels'))->toContain('discord');
});
