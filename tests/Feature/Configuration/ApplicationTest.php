<?php

use App\Enums\UserRole;
use App\Facades\AppConfig;
use App\Livewire\Configuration\Application;
use App\Models\User;
use Livewire\Livewire;

test('configuration route redirects to application tab', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($user)
        ->get('/configuration')
        ->assertRedirect(route('configuration.application'));
});

test('application page displays environment variables', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    Livewire::actingAs($user)
        ->test(Application::class)
        ->assertSee('Configuration')
        ->assertSee('APP_DEBUG')
        ->assertSee('APP_DISPLAY_TIMEZONE')
        ->assertSee('TRUSTED_PROXIES');
});

test('application page displays adminer settings', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->test(Application::class)
        ->assertSet('form.adminer_enabled', true)
        ->assertSet('form.adminer_role', 'admin')
        ->assertSee('Database Browser');
});

test('saving application config persists adminer settings', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->test(Application::class)
        ->set('form.adminer_enabled', true)
        ->set('form.adminer_role', 'member')
        ->call('saveApplicationConfig')
        ->assertHasNoErrors();

    expect(AppConfig::get('app.adminer_enabled'))->toBe(true)
        ->and(AppConfig::get('app.adminer_role'))->toBe('member');
});

test('non-admin cannot save application config', function () {
    Livewire::actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->test(Application::class)
        ->call('saveApplicationConfig')
        ->assertForbidden();
});
