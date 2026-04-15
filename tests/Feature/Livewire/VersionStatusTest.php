<?php

use App\Livewire\VersionStatus;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.version' => null, 'app.commit_hash' => null]);
    Cache::forget('github_latest_release');
    Livewire::withoutLazyLoading();
});

test('component is rendered in the layout', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertSeeLivewire(VersionStatus::class);
});

test('up to date: shows version and success alert in modal', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0'])]);
    config(['app.version' => 'v1.0.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSee('v1.0.0')
        ->assertDontSee(__('available'))
        ->call('open')
        ->assertSee(__('You are running the latest version'));
});

test('update available: shows pill and warning alert in modal', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.0'])]);
    config(['app.version' => 'v1.0.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSee('v1.2.0')
        ->assertSee(__('available'))
        ->call('open')
        ->assertSee(__('Update available:'))
        ->assertSee('v1.0.0')
        ->assertSee('v1.2.0');
});

test('no version set: shows plain link and does not fetch github api', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.0'])]);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSee(__('How to update?'))
        ->assertSet('latestVersion', null);

    Http::assertNothingSent();
});

test('falls back to commit hash when version is not set', function () {
    config(['app.commit_hash' => 'abc1234']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSet('currentVersion', 'abc1234')
        ->assertSee('abc1234');
});

test('modal contains update instructions for all deployment methods', function () {
    config(['app.version' => 'v1.0.0']);
    Http::fake(['api.github.com/*' => Http::response([], 404)]);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->call('open')
        ->assertSee('docker compose pull')
        ->assertSee('helm repo update')
        ->assertSee('docker pull davidcrty/databasement:1');
});

test('github response is cached and reused on subsequent mounts', function () {
    config(['app.version' => 'v1.0.0']);
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

test('github api failure is cached to avoid retries', function () {
    config(['app.version' => 'v1.0.0']);
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSet('latestVersion', null);

    expect(Cache::get('github_latest_release'))->toBe('');
});
