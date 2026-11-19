<?php

use App\Services\Backup\DTO\RestoreOptions;

test('empty options are left out of the stored shape', function () {
    expect((new RestoreOptions(ownerUser: '   '))->toArray())->toBe([]);
});

test('the owner user is trimmed', function () {
    expect((new RestoreOptions(ownerUser: ' pg_owner '))->ownerUser)->toBe('pg_owner');
});

test('stored options round-trip', function () {
    $stored = [
        'force_database' => true,
        'owner_user' => 'pg_owner',
        'parallel_restore' => true,
        'restore_tables' => [
            ['schema' => 'budget', 'table' => 's_saleorder'],
        ],
    ];

    expect(RestoreOptions::fromArray($stored)->toArray())->toBe($stored);
});

test('options read from an array ignore unknown keys and normalize the rest', function () {
    expect(RestoreOptions::fromArray(['force_database' => 'false', 'owner_user' => ' x ', 'unknown' => 1])->toArray())
        ->toBe(['owner_user' => 'x']);
});

test('empty restore_tables are left out of the stored shape', function () {
    expect((new RestoreOptions(restoreTables: []))->toArray())->toBe([]);
});

test('parseQualifiedTables accepts comma and newline separated schema.table names', function () {
    expect(RestoreOptions::parseQualifiedTables("budget.s_saleorder, public.users\nbudget.web_api_log\nbad\n.only"))
        ->toBe([
            ['schema' => 'budget', 'table' => 's_saleorder'],
            ['schema' => 'public', 'table' => 'users'],
            ['schema' => 'budget', 'table' => 'web_api_log'],
        ]);
});

test('schemas and exclude tables round-trip in the stored shape', function () {
    $stored = [
        'restore_schemas' => ['budget', 'public'],
        'exclude_restore_tables' => [
            ['schema' => 'budget', 'table' => 'web_api_log'],
        ],
    ];

    expect(RestoreOptions::fromArray($stored)->toArray())->toBe($stored);
});
