<?php

use App\Livewire\Configuration\Application;
use App\Models\User;
use Livewire\Livewire;

test('configuration route redirects to application tab', function () {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('/configuration')
        ->assertRedirect(route('configuration.application'));
});

test('application page displays environment variables', function () {
    $user = User::factory()->superAdmin()->create();

    Livewire::actingAs($user)
        ->test(Application::class)
        ->assertSee('Configuration')
        ->assertSee('APP_DEBUG')
        ->assertSee('APP_DISPLAY_TIMEZONE')
        ->assertSee('TRUSTED_PROXIES');
});

test('viewing the application settings needs no ability (read-only for everyone)', function () {
    Livewire::actingAs(User::factory()->withAbilities([])->create())
        ->test(Application::class)
        ->assertOk()
        ->assertSee('APP_DEBUG');
});

test('super admin can toggle the global Adminer setting', function () {
    $admin = User::factory()->superAdmin()->create();

    Livewire::actingAs($admin)
        ->test(Application::class)
        ->set('form.adminer_enabled', false)
        ->call('saveApplicationConfig')
        ->assertHasNoErrors();

    expect((bool) \App\Facades\AppConfig::get('app.adminer_enabled'))->toBeFalse();
});

test('a non-super-admin cannot save the application settings', function () {
    Livewire::actingAs(User::factory()->withAbilities([])->create())
        ->test(Application::class)
        ->call('saveApplicationConfig')
        ->assertForbidden();
});
