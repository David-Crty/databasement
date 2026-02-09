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
    AppConfig::set('notifications.telegram.chat_id', null);
    AppConfig::set('notifications.pushover.user_key', null);
    AppConfig::set('notifications.gotify.url', null);
    AppConfig::set('notifications.webhook.url', null);

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

test('saving telegram config persists values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', ['telegram'])
        ->set('form.telegram_bot_token', 'bot123:abc')
        ->set('form.telegram_chat_id', '-100123456')
        ->call('saveNotificationConfig')
        ->assertHasNoErrors()
        ->assertSet('form.has_telegram_bot_token', true)
        ->assertSet('form.telegram_bot_token', '');

    expect(AppConfig::get('notifications.telegram.chat_id'))->toBe('-100123456');
});

test('saving gotify config persists values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', ['gotify'])
        ->set('form.gotify_url', 'https://gotify.example.com')
        ->set('form.gotify_token', 'app-token-xyz')
        ->call('saveNotificationConfig')
        ->assertHasNoErrors()
        ->assertSet('form.has_gotify_token', true)
        ->assertSet('form.gotify_token', '');

    expect(AppConfig::get('notifications.gotify.url'))->toBe('https://gotify.example.com');
});

test('saving pushover config persists values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', ['pushover'])
        ->set('form.pushover_token', 'app-token-abc')
        ->set('form.pushover_user_key', 'user-key-xyz')
        ->call('saveNotificationConfig')
        ->assertHasNoErrors()
        ->assertSet('form.has_pushover_token', true)
        ->assertSet('form.pushover_token', '');

    expect(AppConfig::get('notifications.pushover.user_key'))->toBe('user-key-xyz');
});

test('saving webhook config persists values', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', ['webhook'])
        ->set('form.webhook_url', 'https://webhook.example.com/hook')
        ->set('form.webhook_secret', 'my-secret')
        ->call('saveNotificationConfig')
        ->assertHasNoErrors()
        ->assertSet('form.has_webhook_secret', true)
        ->assertSet('form.webhook_secret', '');

    expect(AppConfig::get('notifications.webhook.url'))->toBe('https://webhook.example.com/hook');
});

test('deselecting new channels nulls their values on save', function () {
    AppConfig::set('notifications.telegram.bot_token', 'bot-token');
    AppConfig::set('notifications.telegram.chat_id', '123');
    AppConfig::set('notifications.pushover.token', 'push-token');
    AppConfig::set('notifications.pushover.user_key', 'push-user');
    AppConfig::set('notifications.gotify.url', 'https://gotify.example.com');
    AppConfig::set('notifications.gotify.token', 'token');
    AppConfig::set('notifications.webhook.url', 'https://webhook.example.com');
    AppConfig::set('notifications.webhook.secret', 'secret');
    AppConfig::flush();

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class)
        ->set('form.channels', [])
        ->call('saveNotificationConfig')
        ->assertHasNoErrors();

    expect(AppConfig::get('notifications.telegram.bot_token'))->toBeNull()
        ->and(AppConfig::get('notifications.telegram.chat_id'))->toBeNull()
        ->and(AppConfig::get('notifications.pushover.token'))->toBeNull()
        ->and(AppConfig::get('notifications.pushover.user_key'))->toBeNull()
        ->and(AppConfig::get('notifications.gotify.url'))->toBeNull()
        ->and(AppConfig::get('notifications.gotify.token'))->toBeNull()
        ->and(AppConfig::get('notifications.webhook.url'))->toBeNull()
        ->and(AppConfig::get('notifications.webhook.secret'))->toBeNull();
});

test('form pre-selects channel when config exists', function (array $setup, string $channel) {
    foreach ($setup as $key => $value) {
        AppConfig::set($key, $value);
    }
    AppConfig::flush();

    $component = Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Index::class);

    expect($component->get('form.channels'))->toContain($channel);
})->with([
    'discord' => [
        ['notifications.discord.token' => 'bot-token', 'notifications.discord.channel_id' => '123456'],
        'discord',
    ],
    'telegram' => [
        ['notifications.telegram.bot_token' => 'bot-token', 'notifications.telegram.chat_id' => '-100123'],
        'telegram',
    ],
    'pushover' => [
        ['notifications.pushover.token' => 'push-token', 'notifications.pushover.user_key' => 'user-key'],
        'pushover',
    ],
    'gotify' => [
        ['notifications.gotify.url' => 'https://gotify.example.com', 'notifications.gotify.token' => 'app-token'],
        'gotify',
    ],
    'webhook' => [
        ['notifications.webhook.url' => 'https://webhook.example.com/hook'],
        'webhook',
    ],
]);
