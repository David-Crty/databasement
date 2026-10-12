<?php

use App\Support\DatabaseServerExtraConfig;

test('it folds type-specific fields into extra_config', function (
    array $data,
    ?array $existing,
    ?string $previousType,
    ?array $expected,
) {
    DatabaseServerExtraConfig::fold($data, $existing, $previousType);

    expect($data['extra_config'])->toEqual($expected);

    // Handled keys are always pulled out of $data.
    foreach (['auth_source', 'dump_flags', 'dump_format', 'dump_privileges', 'ssl_enabled', 'connection_database'] as $key) {
        expect($data)->not->toHaveKey($key);
    }
})->with([
    // [data, existing, previousType, expected extra_config]

    'keeps type-specific string value' => [
        ['database_type' => 'mongodb', 'auth_source' => 'records'], null, null, ['auth_source' => 'records'],
    ],
    'keeps custom dump_format constant' => [
        ['database_type' => 'postgres', 'dump_format' => 'custom'], null, null, ['dump_format' => 'custom'],
    ],
    'keeps boolean flag when enabled' => [
        ['database_type' => 'mysql', 'ssl_enabled' => true], null, null, ['ssl_enabled' => true],
    ],
    'keeps ssl_enabled for postgres' => [
        ['database_type' => 'postgres', 'ssl_enabled' => true], null, null, ['ssl_enabled' => true],
    ],
    'keeps connection_database for postgres' => [
        ['database_type' => 'postgres', 'connection_database' => 'app_db'], null, null, ['connection_database' => 'app_db'],
    ],
    'drops empty connection_database' => [
        ['database_type' => 'postgres', 'connection_database' => ''], null, null, null,
    ],
    'drops whitespace-only connection_database' => [
        ['database_type' => 'postgres', 'connection_database' => '   '], null, null, null,
    ],
    'trims surrounding whitespace from connection_database' => [
        ['database_type' => 'postgres', 'connection_database' => ' app_db '], null, null, ['connection_database' => 'app_db'],
    ],
    'drops connection_database for non-postgres types' => [
        ['database_type' => 'mysql', 'connection_database' => 'app_db'], null, null, null,
    ],
    'drops field not relevant to the type' => [
        ['database_type' => 'sqlite', 'dump_flags' => '--anything'], null, null, null,
    ],
    'folds multiple keys at once' => [
        ['database_type' => 'postgres', 'dump_flags' => '-v', 'dump_format' => 'custom', 'dump_privileges' => true],
        null, null,
        ['dump_flags' => '-v', 'dump_format' => 'custom', 'dump_privileges' => true],
    ],
    'clears existing key when value no longer qualifies' => [
        ['database_type' => 'mongodb', 'auth_source' => ''], ['auth_source' => 'records'], null, null,
    ],
    'preserves existing config when key absent from data' => [
        ['database_type' => 'mongodb'], ['auth_source' => 'records'], null, ['auth_source' => 'records'],
    ],
    'resets stale config on type change' => [
        ['database_type' => 'mysql', 'ssl_enabled' => true], ['auth_source' => 'records'], 'mongodb', ['ssl_enabled' => true],
    ],
    'clears stale config on type change when no replacement keys are provided' => [
        ['database_type' => 'mysql'], ['auth_source' => 'records'], 'mongodb', null,
    ],
]);
