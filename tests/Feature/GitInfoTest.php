<?php

use App\Support\GitInfo;

it('returns version label with version and commit hash', function () {
    config(['app.version' => '1.2.3', 'app.commit_hash' => 'abc1234567890']);

    expect(GitInfo::getVersionLabel())->toBe('v1.2.3 (abc1234)');
});

it('returns commit hash when no version is set', function () {
    config(['app.version' => null, 'app.commit_hash' => 'abc1234567890']);

    expect(GitInfo::getVersionLabel())->toBe('abc1234');
});

it('displays version label in footer', function () {
    config(['app.version' => '2.0.0', 'app.commit_hash' => 'def5678901234']);

    $response = $this->actingAs(\App\Models\User::factory()->create())
        ->get('/dashboard');

    $response->assertSee('v2.0.0 (def5678)');
});
