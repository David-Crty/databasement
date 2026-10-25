<?php

use App\Models\User;

test('the analytics snippet is added to the head of guest and authenticated pages', function () {
    config(['app.analytics_snippet' => '<script data-analytics="test"></script>']);
    $user = User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<script data-analytics="test"></script>', false);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<script data-analytics="test"></script>', false);
});

test('no analytics snippet is added by default', function () {
    User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('data-analytics', false);
});
