<?php

use Illuminate\Support\Carbon;

test('health check returns success', function () {
    $response = $this->getJson(route('health.up'));

    $response->assertOk()
        ->assertJson(['success' => true]);
});

test('health debug returns application info', function () {
    $response = $this->getJson(route('health.debug'));

    $response->assertOk()
        ->assertJsonStructure([
            'ip_address',
            'url',
            'path',
            'hostname',
            'timestamp',
            'date_time_utc',
            'date_time_app',
            'timezone',
            'secure',
            'is_trusted_proxy',
        ]);
});

test('health debug renders date_time_app in display timezone and exposes it in debug mode', function () {
    config(['app.timezone' => 'UTC']);
    config(['app.display_timezone' => 'Asia/Tokyo']);
    config(['app.debug' => true]);

    Carbon::setTestNow(Carbon::parse('2026-05-27 00:00:00', 'UTC'));

    try {
        $response = $this->getJson(route('health.debug'));

        $response->assertOk()
            ->assertJsonPath('date_time_utc', '2026-05-27 00:00:00')
            ->assertJsonPath('date_time_app', '2026-05-27 09:00:00')
            ->assertJsonPath('app_display_timezone', 'Asia/Tokyo');
    } finally {
        Carbon::setTestNow();
    }
});
