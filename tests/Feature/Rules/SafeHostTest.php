<?php

use App\Enums\Ability;
use App\Enums\DatabaseType;
use App\Models\User;
use App\Rules\SafeHost;
use Illuminate\Support\Facades\Validator;

test('SafeHost accepts or rejects a host', function (string $host, bool $valid) {
    $passes = Validator::make(
        ['host' => $host],
        ['host' => [new SafeHost]],
    )->passes();

    expect($passes)->toBe($valid);
})->with([
    'hostname' => ['db.example.com', true],
    'docker service name' => ['mysql_primary', true],
    'ipv4' => ['10.0.0.5', true],
    'ipv6 literal' => ['[::1]', true],

    // The host lands in a MongoDB URI authority and in PDO DSNs, where these
    // characters are delimiters rather than data.
    'mongodb credential delimiter' => ['internal.mongo.corp@attacker.com', false],
    'pdo dsn separator' => ['db.example.com;dbname=other', false],
    'mssql dsn separator' => ['db.example.com,1433', false],
    'path delimiter' => ['db.example.com/evil', false],
    'whitespace' => ['db.example.com evil', false],
    // `$` would match before a final newline, so the pattern anchors with \z.
    'trailing newline' => ["db.example.com\n", false],
]);

test('SafeHost accepts a socket path only for types that support one', function (string $host, ?DatabaseType $type, bool $valid) {
    $passes = Validator::make(
        ['host' => $host],
        ['host' => [new SafeHost($type)]],
    )->passes();

    expect($passes)->toBe($valid);
})->with([
    'postgres socket directory' => ['/var/run/postgresql_mount', DatabaseType::POSTGRESQL, true],
    'mysql socket file' => ['/var/run/mysqld/mysqld.sock', DatabaseType::MYSQL, true],
    'mongodb socket' => ['/tmp/mongodb-27017.sock', DatabaseType::MONGODB, false],
    'no database type' => ['/var/run/postgresql', null, false],
    'relative path' => ['var/run/postgresql', DatabaseType::POSTGRESQL, false],
    'socket path with dsn separator' => ['/var/run;dbname=other', DatabaseType::POSTGRESQL, false],
    'socket path with trailing newline' => ["/var/run/postgresql\n", DatabaseType::POSTGRESQL, false],
]);

test('the database server api rejects a host that redirects the connection', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'redirected',
            'database_type' => 'mongodb',
            'host' => 'internal.mongo.corp@attacker.com',
            'port' => 27017,
            'username' => 'user',
            'password' => 'secret',
            'backups_enabled' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('host');
});

test('a sqlite backup path cannot escape via traversal', function () {
    $user = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/database-servers', [
            'name' => 'probe',
            'database_type' => 'sqlite',
            'backups_enabled' => true,
            'backups' => [[
                'volume_ids' => [App\Models\Volume::factory()->create()->id],
                'backup_schedule_id' => dailySchedule()->id,
                'retention_policy' => 'days',
                'retention_days' => 7,
                'database_names' => ['/var/lib/data/../../../etc/passwd'],
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('backups.0.database_names.0');
});
