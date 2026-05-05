<?php

use App\Livewire\Configuration\Notification;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;

test('admin can create a notification channel', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Notification::class)
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
        ->test(Notification::class)
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
        ->test(Notification::class)
        ->call('confirmDeleteChannel', $channel->id)
        ->assertSet('showDeleteChannelModal', true)
        ->call('deleteChannel')
        ->assertSet('showDeleteChannelModal', false);

    $this->assertDatabaseMissing('notification_channels', ['id' => $channel->id]);
});

test('non-admin cannot save notification channel', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Notification::class)
        ->call('saveChannel')
        ->assertForbidden();
});

test('non-admin cannot delete notification channel', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Notification::class)
        ->call('deleteChannel')
        ->assertForbidden();
});

test('non-admin cannot send test notification', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'member']))
        ->test(Notification::class)
        ->call('sendTestNotification', 'fake-id')
        ->assertForbidden();
});

test('sendTestNotification sends notification for a channel', function () {
    $channel = NotificationChannel::factory()->email()->create([
        'name' => 'Test Email',
        'config' => ['to' => 'admin@example.com'],
    ]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Notification::class)
        ->call('sendTestNotification', $channel->id);

    NotificationFacade::assertSentTimes(BackupFailedNotification::class, 1);
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
        ->test(Notification::class)
        ->call('sendTestNotification', $channel->id)
        ->assertSuccessful();
});

test('admin can create notification channels of various types', function (string $type, array $formFields, array $expectedOnEdit) {
    $component = Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(Notification::class)
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
        ->test(Notification::class)
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
