<?php

use App\Livewire\Settings\Preferences;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;

test('middleware applies locale from cookie', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withCookie('locale', 'fr')
        ->get(route('preferences.edit'))
        ->assertOk();

    expect(app()->getLocale())->toBe('fr');
});

test('middleware detects locale from Accept-Language header', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9,en;q=0.8')
        ->get(route('preferences.edit'))
        ->assertOk();

    expect(app()->getLocale())->toBe('fr');
});

test('cookie takes priority over Accept-Language header', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withCookie('locale', 'en')
        ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get(route('preferences.edit'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

test('unknown locale falls back to default', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withCookie('locale', 'xx')
        ->get(route('preferences.edit'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

test('setting locale via component sets cookie', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Preferences::class)
        ->call('setLocale', 'fr')
        ->assertRedirect(route('preferences.edit'));

    $cookie = Cookie::queued('locale');
    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe('fr');
});

test('setting invalid locale is ignored', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Preferences::class)
        ->call('setLocale', 'xx')
        ->assertNoRedirect()
        ->assertSet('locale', 'en');
});

test('preferences page shows language selector', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Preferences::class)
        ->assertSee('English')
        ->assertSee('Français');
});

test('french translations are applied when locale is fr', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withCookie('locale', 'fr')
        ->get(route('preferences.edit'))
        ->assertOk()
        ->assertSee('Apparence');
});
