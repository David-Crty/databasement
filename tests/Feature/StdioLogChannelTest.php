<?php

use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;

test('the stdio log channel sends warnings and above to stderr and the rest to stdout', function () {
    $stdout = fopen('php://memory', 'w+');
    $stderr = fopen('php://memory', 'w+');
    config([
        'logging.channels.stdio_stdout.with.stream' => $stdout,
        'logging.channels.stdio_stderr.with.stream' => $stderr,
    ]);

    Log::channel('stdio')->info('routine message');
    Log::channel('stdio')->warning('something to look at');
    Log::channel('stdio')->error('something broke');

    expect(stream_get_contents($stdout, offset: 0))
        ->toContain('routine message')
        ->not->toContain('something to look at')
        ->not->toContain('something broke')
        ->and(stream_get_contents($stderr, offset: 0))
        ->toContain('something to look at')
        ->toContain('something broke')
        ->not->toContain('routine message');
});

test('the stdio log channel lets warnings reach the channels stacked after it', function () {
    $other = fopen('php://memory', 'w+');
    config([
        'logging.channels.stdio_stdout.with.stream' => fopen('php://memory', 'w+'),
        'logging.channels.stdio_stderr.with.stream' => fopen('php://memory', 'w+'),
        'logging.channels.other' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => $other]],
    ]);

    Log::stack(['stdio', 'other'])->error('something broke');

    expect(stream_get_contents($other, offset: 0))->toContain('something broke');
});
