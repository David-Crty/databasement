<?php

use App\Services\Backup\Databases\MssqlDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;

beforeEach(function () {
    $this->db = new MssqlDatabase;
    $this->db->setConfig([
        'host' => 'mssql.example.com',
        'port' => 1433,
        'user' => 'sa',
        'pass' => 'Pa55w0rd!',
        'database' => 'app_db',
    ]);
});

test('dump produces sqlpackage export command', function () {
    $result = $this->db->dump('/tmp/snapshot.bacpac');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe(
            "sqlpackage /Action:Export /TargetFile:'/tmp/snapshot.bacpac' "
            ."/SourceServerName:'mssql.example.com,1433' "
            ."/SourceDatabaseName:'app_db' "
            ."/SourceUser:'sa' "
            ."/SourcePassword:'Pa55w0rd!' "
            .'/SourceTrustServerCertificate:True '
            .'/SourceEncryptConnection:True'
        );
});

test('dump appends user-provided dump flags', function () {
    $this->db->setConfig([
        'host' => 'mssql.example.com',
        'port' => 1433,
        'user' => 'sa',
        'pass' => 'Pa55w0rd!',
        'database' => 'app_db',
        'dump_flags' => '/Verbose:True',
    ]);

    $result = $this->db->dump('/tmp/snapshot.bacpac');

    expect($result->command)->toEndWith("/SourceEncryptConnection:True '/Verbose:True'");
});

test('restore produces sqlpackage import command when input is already a .bacpac', function () {
    $result = $this->db->restore('/tmp/snapshot.bacpac');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe(
            "sqlpackage /Action:Import /SourceFile:'/tmp/snapshot.bacpac' "
            ."/TargetServerName:'mssql.example.com,1433' "
            ."/TargetDatabaseName:'app_db' "
            ."/TargetUser:'sa' "
            ."/TargetPassword:'Pa55w0rd!' "
            .'/TargetTrustServerCertificate:True '
            .'/TargetEncryptConnection:True'
        );
});

test('restore renames extensionless input to .bacpac before invoking sqlpackage', function () {
    $result = $this->db->restore('/tmp/snapshot');

    expect($result->command)->toStartWith("mv '/tmp/snapshot' '/tmp/snapshot.bacpac' && sqlpackage /Action:Import /SourceFile:'/tmp/snapshot.bacpac'");
});

test('listDatabases filters out system databases', function () {
    $statement = Mockery::mock(\PDOStatement::class);
    $statement->shouldReceive('fetchAll')
        ->once()
        ->with(PDO::FETCH_COLUMN, 0)
        ->andReturn(['master', 'tempdb', 'model', 'msdb', 'app_db', 'reports']);

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')
        ->once()
        ->with('SELECT name FROM sys.databases ORDER BY name')
        ->andReturn($statement);

    $db = Mockery::mock(MssqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->once()->andReturn($pdo);
    $db->setConfig(['host' => 'h', 'port' => 1433, 'user' => 'u', 'pass' => 'p', 'database' => '']);

    expect($db->listDatabases())->toBe(['app_db', 'reports']);
});

test('testConnection returns success with parsed version', function () {
    $version = 'Microsoft SQL Server 2022 (RTM) - 16.0.1000.6 (X64)';
    $statement = Mockery::mock(\PDOStatement::class);
    $statement->shouldReceive('fetchColumn')->andReturn($version);

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')->with('SELECT @@VERSION')->andReturn($statement);

    $db = Mockery::mock(MssqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->once()->andReturn($pdo);
    $db->setConfig(['host' => 'h', 'port' => 1433, 'user' => 'u', 'pass' => 'p', 'database' => 'app_db']);

    $result = $db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details'])->toHaveKey('ping_ms')
        ->and($result['details']['output'])->toContain('Microsoft SQL Server 2022');
});

test('testConnection returns failure when PDO throws', function () {
    $db = Mockery::mock(MssqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->once()->andThrow(new PDOException('Login failed for user'));
    $db->setConfig(['host' => 'h', 'port' => 1433, 'user' => 'u', 'pass' => 'p', 'database' => 'app_db']);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Login failed');
});
