<?php

use App\Services\Backup\DTO\RestoreOptions;

test('empty options are left out of the stored shape', function () {
    expect((new RestoreOptions(ownerUser: '   '))->toArray())->toBe([]);
});

test('the owner user is trimmed', function () {
    expect((new RestoreOptions(ownerUser: ' pg_owner '))->ownerUser)->toBe('pg_owner');
});

test('stored options round-trip', function () {
    $stored = ['force_database' => true, 'owner_user' => 'pg_owner', 'parallel_restore' => true];

    expect(RestoreOptions::fromArray($stored)->toArray())->toBe($stored);
});

test('options read from an array ignore unknown keys and normalize the rest', function () {
    expect(RestoreOptions::fromArray(['force_database' => 'false', 'owner_user' => ' x ', 'unknown' => 1])->toArray())
        ->toBe(['owner_user' => 'x']);
});
