<?php

use App\Services\Backup\OutputBuffer;

test('short output is kept verbatim', function () {
    $buffer = new OutputBuffer(64, 64);
    $buffer->append("first\n");
    $buffer->append("second\n");

    expect($buffer->isTruncated())->toBeFalse()
        ->and($buffer->toString())->toBe("first\nsecond\n");
});

test('long output keeps the head and the tail and drops the middle', function () {
    $buffer = new OutputBuffer(64, 64);

    for ($i = 1; $i <= 500; $i++) {
        $buffer->append("warning line {$i}\n");
    }

    $result = $buffer->toString();

    expect($buffer->isTruncated())->toBeTrue()
        ->and($result)->toContain('warning line 1')
        ->and($result)->toContain('warning line 500')
        ->and($result)->not->toContain('warning line 250')
        ->and($result)->toContain('of output omitted');
});

test('truncated output stays within the configured budget', function () {
    $buffer = new OutputBuffer(1024, 1024);

    for ($i = 0; $i < 10_000; $i++) {
        $buffer->append(str_repeat('x', 200)."\n");
    }

    // Head + tail + the marker line, well under the 2 MB that was appended.
    expect(strlen($buffer->toString()))->toBeLessThan(1024 + 1024 + 200);
});

test('lines are never split in half by truncation', function () {
    $buffer = new OutputBuffer(64, 64);

    for ($i = 1; $i <= 200; $i++) {
        $buffer->append(sprintf("line-%03d-end\n", $i));
    }

    foreach (explode("\n", trim($buffer->toString())) as $line) {
        if (str_starts_with($line, '[...')) {
            continue;
        }

        expect($line)->toMatch('/^line-\d{3}-end$/');
    }
});

test('output arriving in chunks smaller than a line is reassembled', function () {
    $buffer = new OutputBuffer(64, 64);

    foreach (str_split("hello world\nsecond line\n", 3) as $chunk) {
        $buffer->append($chunk);
    }

    expect($buffer->toString())->toBe("hello world\nsecond line\n");
});

test('output with no line breaks is still bounded', function () {
    $buffer = new OutputBuffer(64, 64);
    $buffer->append(str_repeat('a', 10_000));

    expect($buffer->isTruncated())->toBeTrue()
        ->and(strlen($buffer->toString()))->toBeLessThan(400);
});
