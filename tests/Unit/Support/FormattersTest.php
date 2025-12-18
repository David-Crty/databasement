<?php

use App\Support\Formatters;

test('humanDuration returns null for null input', function () {
    expect(Formatters::humanDuration(null))->toBeNull();
});

test('humanDuration formats milliseconds under 1 second', function () {
    expect(Formatters::humanDuration(0))->toBe('0ms')
        ->and(Formatters::humanDuration(1))->toBe('1ms')
        ->and(Formatters::humanDuration(500))->toBe('500ms')
        ->and(Formatters::humanDuration(999))->toBe('999ms');
});

test('humanDuration formats seconds under 1 minute', function () {
    expect(Formatters::humanDuration(1000))->toBe('1s')
        ->and(Formatters::humanDuration(1500))->toBe('1.5s')
        ->and(Formatters::humanDuration(30000))->toBe('30s')
        ->and(Formatters::humanDuration(59000))->toBe('59s');
});

test('humanDuration formats minutes and seconds', function () {
    expect(Formatters::humanDuration(60000))->toBe('1m 0s')
        ->and(Formatters::humanDuration(90000))->toBe('1m 30s')
        ->and(Formatters::humanDuration(125000))->toBe('2m 5s')
        ->and(Formatters::humanDuration(3661000))->toBe('61m 1s');
});
