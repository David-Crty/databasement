<?php

use App\Models\DatabaseServer;

test('filterDatabasesByPattern matches databases by prefix', function () {
    $databases = ['prod_users', 'prod_orders', 'staging_users', 'test_db'];

    $result = DatabaseServer::filterDatabasesByPattern($databases, '^prod_');

    expect($result)->toBe(['prod_users', 'prod_orders']);
});

test('filterDatabasesByPattern is case insensitive', function () {
    $databases = ['MyApp', 'myapp_test', 'MYAPP_PROD'];

    $result = DatabaseServer::filterDatabasesByPattern($databases, 'myapp');

    expect($result)->toBe(['MyApp', 'myapp_test', 'MYAPP_PROD']);
});

test('filterDatabasesByPattern supports negative lookahead to exclude databases', function () {
    $databases = ['prod_users', 'test_users', 'test_orders', 'staging_db'];

    $result = DatabaseServer::filterDatabasesByPattern($databases, '^(?!test_)');

    expect($result)->toBe(['prod_users', 'staging_db']);
});

test('filterDatabasesByPattern returns empty array for empty pattern', function () {
    $databases = ['db1', 'db2'];

    $result = DatabaseServer::filterDatabasesByPattern($databases, '');

    expect($result)->toBe([]);
});

test('filterDatabasesByPattern returns empty array for invalid regex', function () {
    $databases = ['db1', 'db2'];

    $result = DatabaseServer::filterDatabasesByPattern($databases, '[invalid');

    expect($result)->toBe([]);
});

test('filterDatabasesByPattern returns empty array when no databases match', function () {
    $databases = ['alpha', 'beta', 'gamma'];

    $result = DatabaseServer::filterDatabasesByPattern($databases, '^prod_');

    expect($result)->toBe([]);
});

test('isValidDatabasePattern returns true for valid patterns', function (string $pattern) {
    expect(DatabaseServer::isValidDatabasePattern($pattern))->toBeTrue();
})->with([
    '^prod_',
    '^(?!test_)',
    'users|orders',
    '.*_backup$',
    'db\d+',
]);

test('isValidDatabasePattern returns false for invalid patterns', function (string $pattern) {
    expect(DatabaseServer::isValidDatabasePattern($pattern))->toBeFalse();
})->with([
    '[invalid',
    '(?P<invalid',
    '',
]);
