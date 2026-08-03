<?php

use App\Enums\Ability;
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
