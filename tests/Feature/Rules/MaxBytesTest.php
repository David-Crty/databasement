<?php

use App\Rules\MaxBytes;
use Illuminate\Support\Facades\Validator;

test('MaxBytes bounds a value by its byte length, not its character count', function (string $value, bool $valid) {
    $passes = Validator::make(
        ['connection_database' => $value],
        ['connection_database' => [new MaxBytes(63)]],
    )->passes();

    expect($passes)->toBe($valid);
})->with([
    // PostgreSQL truncates an identifier past NAMEDATALEN-1, counted in bytes,
    // so Laravel's character-based `max:63` lets a name through that the server
    // then silently cuts short.
    '63 ascii bytes' => [str_repeat('a', 63), true],
    '64 ascii bytes' => [str_repeat('a', 64), false],
    '31 two-byte characters, 62 bytes' => [str_repeat('é', 31), true],
    '32 two-byte characters, 64 bytes' => [str_repeat('é', 32), false],
]);
