<?php

use App\Livewire\Configuration\Index;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('configuration page displays expected settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('configuration.index'))
        ->assertOk()
        ->assertSee('Configuration')
        ->assertSee('BACKUP_WORKING_DIRECTORY')
        ->assertSee('BACKUP_COMPRESSION');
});

test('sendTestNotification sends notification when enabled', function () {
    config([
        'notifications.enabled' => true,
        'notifications.mail.to' => 'admin@example.com',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->call('sendTestNotification');

    Notification::assertSentOnDemand(
        BackupFailedNotification::class,
        fn ($notification) => $notification->snapshot->databaseServer->name === '[TEST] Production Database'
            && str_contains($notification->exception->getMessage(), 'This is a test notification')
    );
});

test('sendTestNotification does not send when notifications disabled', function () {
    config([
        'notifications.enabled' => false,
        'notifications.mail.to' => 'admin@example.com',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(Index::class)
        ->call('sendTestNotification');

    // Notification service returns early when disabled, but no exception is thrown
    // so success message is still flashed - this is expected behavior
    Notification::assertNothingSent();
});
