<?php

use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;

test('forConnectionTest creates temporary server with SSH config', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'bastion.example.com',
        'username' => 'tunnel_user',
    ]);

    $server = DatabaseServer::forConnectionTest([
        'host' => 'private-db.internal',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'dbuser',
        'password' => 'secret',
    ], $sshConfig);

    expect($server->host)->toBe('private-db.internal')
        ->and($server->port)->toBe(3306)
        ->and($server->username)->toBe('dbuser')
        ->and($server->requiresSshTunnel())->toBeTrue()
        ->and($server->sshConfig)->toBe($sshConfig)
        ->and($server->exists)->toBeFalse(); // Not persisted
});

test('forConnectionTest creates temporary server without SSH config', function () {
    $server = DatabaseServer::forConnectionTest([
        'host' => 'db.example.com',
        'port' => 5432,
        'database_type' => 'postgres',
        'username' => 'pguser',
        'password' => 'secret',
    ]);

    expect($server->host)->toBe('db.example.com')
        ->and($server->port)->toBe(5432)
        ->and($server->requiresSshTunnel())->toBeFalse()
        ->and($server->sshConfig)->toBeNull()
        ->and($server->exists)->toBeFalse();
});

test('forConnectionTest uses default port when not specified', function () {
    $server = DatabaseServer::forConnectionTest([
        'host' => 'db.example.com',
    ]);

    expect($server->port)->toBe(3306); // Default MySQL port
});

test('getConnectionLabel returns basename for SQLite', function () {
    $server = DatabaseServer::factory()->make([
        'database_type' => 'sqlite',
    ]);
    // Simulate the form's connection test state where paths aren't yet persisted
    $server->pendingDatabaseNames = ['/var/data/myapp.sqlite'];

    expect($server->getConnectionLabel())->toBe('myapp.sqlite')
        ->and($server->getConnectionDetails())->toBe('/var/data/myapp.sqlite');
});

test('getConnectionLabel returns host:port for client-server databases', function () {
    $server = DatabaseServer::factory()->make([
        'database_type' => 'mysql',
        'host' => 'db.example.com',
        'port' => 3306,
    ]);

    expect($server->getConnectionLabel())->toBe('db.example.com:3306')
        ->and($server->getConnectionDetails())->toBe('db.example.com:3306');
});

test('getSshDisplayName returns null when SSH not configured', function () {
    $server = DatabaseServer::factory()->make();

    expect($server->getSshDisplayName())->toBeNull();
});

test('getSshDisplayName returns display name when SSH configured', function () {
    $server = DatabaseServer::factory()->withSshTunnel()->create();

    expect($server->getSshDisplayName())->not->toBeNull()
        ->and($server->getSshDisplayName())->toContain('@'); // Format: user@host:port
});

test('supportsAdminer is false for agent-backed servers', function () {
    $agent = Agent::factory()->create();
    $agentBacked = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'agent_id' => $agent->id,
    ]);
    $direct = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'agent_id' => null,
    ]);

    expect($agentBacked->supportsAdminer())->toBeFalse()
        ->and($direct->supportsAdminer())->toBeTrue();
});

test('requiresSftpTransfer returns correct value', function () {
    $sqliteWithSsh = DatabaseServer::factory()->sqliteRemote()->create();
    $sqliteLocal = DatabaseServer::factory()->sqlite()->create();
    $mysqlWithSsh = DatabaseServer::factory()->withSshTunnel()->create();

    expect($sqliteWithSsh->ssh_config_id)->not->toBeNull()
        ->and($sqliteWithSsh->requiresSftpTransfer())->toBeTrue()
        ->and($sqliteLocal->requiresSftpTransfer())->toBeFalse()
        ->and($mysqlWithSsh->requiresSftpTransfer())->toBeFalse();
});

test('buildExtraConfig folds type-specific fields into extra_config', function (
    array $data,
    ?array $existing,
    ?string $previousType,
    ?array $expected,
) {
    DatabaseServer::buildExtraConfig($data, $existing, $previousType);

    expect($data['extra_config'])->toEqual($expected);

    // Handled keys are always pulled out of $data.
    foreach (['auth_source', 'dump_flags', 'dump_format', 'dump_privileges', 'ssl_enabled'] as $key) {
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
