<?php

use App\Livewire\VersionStatus;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.version' => null]);
    Cache::forget('github_latest_release');
    Livewire::withoutLazyLoading();
});

test('component is rendered in the layout', function () {
    Http::fake(['api.github.com/*' => Http::response([], 404)]);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertSeeLivewire(VersionStatus::class);
});

test('up to date: shows version in footer and success alert in modal', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0'])]);
    config(['app.version' => '1.0.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSee('v1.0.0')
        ->assertDontSee(__('available'))
        ->call('open')
        ->assertSet('showModal', true)
        ->assertSee(__('You are running the latest version'));
});

test('update available: shows pill in footer and warning alert in modal', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.0'])]);
    config(['app.version' => '1.0.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSee('v1.2.0')
        ->assertSee(__('available'))
        ->call('open')
        ->assertSee(__('Update available:'))
        ->assertSee('v1.0.0')
        ->assertSee('v1.2.0');
});

test('no version info: shows plain link in footer and warning in modal', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSee(__('How to update?'))
        ->call('open')
        ->assertSee(__('Could not determine version information.'));
});

test('modal contains update instructions for all deployment methods', function () {
    Http::fake(['api.github.com/*' => Http::response([], 404)]);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->call('open')
        ->assertSee('docker compose pull')
        ->assertSee('helm repo update')
        ->assertSee('docker pull davidcrty/databasement:1');
});

test('github response is cached and failures avoid retries', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.3'])]);

    $user = User::factory()->create();
    Livewire::actingAs($user)->test(VersionStatus::class);

    expect(Cache::get('github_latest_release'))->toBe('v1.2.3');

    // Second mount uses cache even when API fails
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(VersionStatus::class)
        ->assertSet('latestVersion', 'v1.2.3');
});

test('github api failure is cached as empty string', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSet('latestVersion', null);

    expect(Cache::get('github_latest_release'))->toBe('');
});
