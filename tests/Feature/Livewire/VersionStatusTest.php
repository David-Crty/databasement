<?php

use App\Livewire\VersionStatus;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class VersionStatusWithoutGit extends VersionStatus
{
    protected function getGitShortHash(): ?string
    {
        return null;
    }
}

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

test('up to date: shows version with green dot and success alert', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0'])]);
    config(['app.version' => 'v1.0.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSet('appVersion', 'v1.0.0')
        ->assertSee('v1.0.0')
        ->assertDontSee(__('available'))
        ->call('open')
        ->assertSee(__('You are running the latest version'));
});

test('current version ahead of latest: treated as up to date', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0'])]);
    config(['app.version' => 'v1.2.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSee('v1.2.0')
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

test('no version or commit hash: shows plain link', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.0'])]);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatusWithoutGit::class)
        ->assertSet('appVersion', null)
        ->assertSet('appCommitHash', null)
        ->assertSee(__('How to update?'));
});

test('commit hash shown when version is not set', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.0'])]);
    config(['app.commit_hash' => 'abc1234def']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSet('appVersion', null)
        ->assertSet('appCommitHash', 'abc1234')
        ->assertSee('abc1234');
});

test('both version and commit hash are set independently', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0'])]);
    config(['app.version' => 'v1.0.0', 'app.commit_hash' => 'abc1234def']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSet('appVersion', 'v1.0.0')
        ->assertSet('appCommitHash', 'abc1234');
});

test('malformed version: renders without error', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0'])]);
    config(['app.version' => 'not-a-version']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSet('appVersion', 'vnot-a-version')
        ->assertOk();
});

test('modal contains update instructions for all deployment methods', function () {
    Http::fake(['api.github.com/*' => Http::response([], 404)]);
    config(['app.version' => 'v1.0.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->call('open')
        ->assertSee('docker compose pull')
        ->assertSee('helm repo update')
        ->assertSee('docker pull davidcrty/databasement:1');
});

test('github response is cached and reused on subsequent mounts', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.2.3'])]);
    config(['app.version' => 'v1.0.0']);

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
    Http::fake(['api.github.com/*' => Http::response([], 500)]);
    config(['app.version' => 'v1.0.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(VersionStatus::class)
        ->assertSet('latestVersion', null);

    expect(Cache::get('github_latest_release'))->toBe('');
});
