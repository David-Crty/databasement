<?php

use App\Rules\SafePath;
use Illuminate\Support\Facades\Validator;

test('rejects path traversal sequences', function (string $path) {
    $validator = Validator::make(
        ['path' => $path],
        ['path' => new SafePath]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('path'))->toContain('path traversal');
})->with([
    '../etc/passwd',
    'foo/../bar',
    'foo/bar/..',
    '..\\windows\\system32',
]);

test('rejects backslashes', function (string $path) {
    $validator = Validator::make(
        ['path' => $path],
        ['path' => new SafePath]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('path'))->toContain('backslash');
})->with([
    'foo\\bar',
    'C:\\Users\\test',
    'path\\to\\file',
]);

test('rejects absolute paths when not allowed', function (string $path) {
    $validator = Validator::make(
        ['path' => $path],
        ['path' => new SafePath(allowAbsolute: false)]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('path'))->toContain('relative path');
})->with([
    '/etc/passwd',
    '/var/backups',
    'C:/',
    'D:/backups',
]);

test('allows absolute paths when configured', function (string $path) {
    $validator = Validator::make(
        ['path' => $path],
        ['path' => new SafePath(allowAbsolute: true)]
    );

    expect($validator->passes())->toBeTrue();
})->with([
    '/var/backups',
    '/home/user/data',
]);

test('allows valid relative paths', function (string $path) {
    $validator = Validator::make(
        ['path' => $path],
        ['path' => new SafePath]
    );

    expect($validator->passes())->toBeTrue();
})->with([
    'backups',
    'mysql/production',
    'data/2024/01',
]);

test('allows empty and null values', function () {
    $validator = Validator::make(
        ['path' => ''],
        ['path' => new SafePath]
    );
    expect($validator->passes())->toBeTrue();

    $validator = Validator::make(
        ['path' => null],
        ['path' => new SafePath]
    );
    expect($validator->passes())->toBeTrue();
});
