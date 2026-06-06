<?php

use App\Enums\DatabaseType;
use Illuminate\Support\Facades\Validator;

// Pest's Unit suite runs without bootstrapping Laravel by default; the rules
// rely on the validator and translator bindings, so opt this file in.
uses(Tests\TestCase::class);

test('databaseNameRules accepts or rejects names per database type', function (DatabaseType $type, string $input, bool $valid) {
    $passes = Validator::make(
        ['name' => $input],
        ['name' => $type->databaseNameRules()],
    )->passes();

    expect($passes)->toBe($valid);
})->with([
    // Default identifier rules (MySQL, PostgreSQL, MSSQL, MongoDB, Redis).
    'mysql identifier' => [DatabaseType::MYSQL, 'my_db_1', true],
    'mysql empty' => [DatabaseType::MYSQL, '', false],
    'mysql dash rejected' => [DatabaseType::MYSQL, 'my-db', false],
    'mysql dot rejected' => [DatabaseType::MYSQL, 'my.db', false],
    'mysql too long' => [DatabaseType::MYSQL, str_repeat('a', 65), false],
    'postgres identifier' => [DatabaseType::POSTGRESQL, 'app_prod', true],
    'postgres slash reject' => [DatabaseType::POSTGRESQL, 'app/prod', false],

    // SQLite is a path: any non-empty string up to 255 chars.
    'sqlite absolute path' => [DatabaseType::SQLITE, '/var/lib/data/app.sqlite', true],
    'sqlite empty rejected' => [DatabaseType::SQLITE, '', false],
    'sqlite too long' => [DatabaseType::SQLITE, str_repeat('a', 256), false],

    // Firebird is a path with a restricted charset.
    'firebird path' => [DatabaseType::FIREBIRD, '/var/lib/firebird/data/sample.fdb', true],
    'firebird windows path' => [DatabaseType::FIREBIRD, 'C:\\firebird\\sample.fdb', true],
    'firebird at sign rejected' => [DatabaseType::FIREBIRD, '/data/foo@bar.fdb', false],
    'firebird quote rejected' => [DatabaseType::FIREBIRD, "/data/foo'.fdb", false],
    'firebird empty rejected' => [DatabaseType::FIREBIRD, '', false],
]);

test('databaseNameMessages keys are prefixed with the field name', function () {
    $messages = DatabaseType::FIREBIRD->databaseNameMessages('schemaName');

    expect($messages)
        ->toHaveKey('schemaName.required')
        ->toHaveKey('schemaName.regex');
});
