<?php

use App\Enums\DatabaseType;
use App\Rules\SafeDumpFlags;
use Illuminate\Support\Facades\Validator;

test('SafeDumpFlags accepts or rejects dump flags', function (DatabaseType $type, string $flags, bool $valid) {
    $passes = Validator::make(
        ['dump_flags' => $flags],
        ['dump_flags' => [new SafeDumpFlags($type)]],
    )->passes();

    expect($passes)->toBe($valid);
})->with([
    'ordinary flags' => [DatabaseType::MYSQL, '--single-transaction --no-tablespaces', true],
    'empty' => [DatabaseType::MYSQL, '', true],
    'characters a flag cannot contain' => [DatabaseType::MYSQL, '--where=`id`', false],

    // The option name is its own token, however its value is attached.
    'long name, attached value' => [DatabaseType::MYSQL, '--result-file=/app/public/shell.php', false],
    'short name, separated value' => [DatabaseType::MYSQL, '-r /app/public/shell.php', false],
    'short name, attached value' => [DatabaseType::MYSQL, '-D/app/public', false],

    // Long names fold case, short names do not: -R is --routines and -d is
    // --no-data, neither of which has anything to do with -r or -D.
    'long name in caps' => [DatabaseType::MYSQL, '--RESULT-FILE=/app/public/shell.php', false],

    // The MySQL clients read _ and - as the same character in a long name.
    'underscored alias' => [DatabaseType::MYSQL, '--result_file=/app/public/shell.php', false],
    'underscored alias of another denied option' => [DatabaseType::MYSQL, '--log_error=/app/public/x.php', false],
    'underscore in a name that is not denied' => [DatabaseType::MYSQL, '--skip_ssl', true],
    'short names in the other case' => [DatabaseType::MYSQL, '-R -d', true],

    // pg_dump and mariadb-dump read a short token as a cluster, so a denied
    // option reaches them even when another option opens the token.
    'clustered short names, postgres' => [DatabaseType::POSTGRESQL, '-vf/app/public/dump.sql', false],
    'clustered short names, mysql' => [DatabaseType::MYSQL, '-vr/app/public/shell.php', false],
    'cluster of short names that are all allowed' => [DatabaseType::MYSQL, '-Rd', true],

    // A denied name must match whole, not as a prefix.
    'longer option starting the same way' => [DatabaseType::MYSQL, '--result-file-suffix=x', true],
    'longer sqlpackage option starting the same way' => [DatabaseType::MSSQL, '/tfoo:x', true],

    // One per engine, so a mis-keyed list does not go unnoticed.
    'mysql config file, an indirect route to the same write' => [DatabaseType::MYSQL, '--defaults-extra-file=/tmp/evil.cnf', false],
    'postgres output file' => [DatabaseType::POSTGRESQL, '--file=/app/public/shell.php', false],
    'mongo output directory' => [DatabaseType::MONGODB, '--out=/app/public', false],
    'redis lua script' => [DatabaseType::REDIS, '--eval /tmp/x.lua', false],
    'sqlpackage target file' => [DatabaseType::MSSQL, '/tf:/app/public/shell.php', false],

    // The same spelling is a legitimate option on another engine.
    'mysql --force is not the postgres --file' => [DatabaseType::MYSQL, '-f', true],
    'redis repeat count is not the mysql --result-file' => [DatabaseType::REDIS, '-r 5', true],
]);

test('SafeDumpFlags names the offending option', function () {
    $errors = Validator::make(
        ['dump_flags' => '--result-file=/app/public/shell.php'],
        ['dump_flags' => [new SafeDumpFlags(DatabaseType::MYSQL)]],
    )->errors();

    expect($errors->first('dump_flags'))->toContain('--result-file=/app/public/shell.php');
});
