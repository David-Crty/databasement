<?php

use App\Services\Backup\Databases\DTO\DatabaseOperationResult;
use App\Services\Backup\Databases\MysqlDatabase;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->db = new MysqlDatabase;
    $this->db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
    ]);
});

test('dump builds correct command', function (string $cliType, string $expectedCommand) {
    config(['backup.mysql_cli_type' => $cliType]);

    $result = $this->db->dump('/tmp/dump.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe($expectedCommand);
})->with([
    'mariadb' => ['mariadb', "mariadb-dump --single-transaction --routines --add-drop-table --complete-insert --hex-blob --quote-names --skip_ssl --host='db.local' --port='3306' --user='root' --password='secret' 'myapp' > '/tmp/dump.sql'"],
    'mysql' => ['mysql', "mysqldump --single-transaction --routines --add-drop-table --complete-insert --hex-blob --quote-names --host='db.local' --port='3306' --user='root' --password='secret' 'myapp' > '/tmp/dump.sql'"],
]);

test('restore builds correct command', function (string $cliType, string $expectedCommand) {
    config(['backup.mysql_cli_type' => $cliType]);

    $result = $this->db->restore('/tmp/restore.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe($expectedCommand);
})->with([
    'mariadb' => ['mariadb', "mariadb --host='db.local' --port='3306' --user='root' --password='secret' --skip_ssl 'myapp' -e 'source /tmp/restore.sql'"],
    'mysql' => ['mysql', "mysql --host='db.local' --port='3306' --user='root' --password='secret' 'myapp' -e 'source /tmp/restore.sql'"],
]);

test('testConnection returns success when process succeeds', function () {
    config(['backup.mysql_cli_type' => 'mariadb']);

    Process::fake([
        '*' => Process::result(output: 'Uptime: 12345'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details'])->toHaveKey('ping_ms')
        ->and($result['details']['output'])->toBe('Uptime: 12345');
});

test('listDatabases returns databases excluding system databases', function () {
    $pdoStatement = Mockery::mock(\PDOStatement::class);
    $pdoStatement->shouldReceive('fetchAll')
        ->once()
        ->with(PDO::FETCH_COLUMN, 0)
        ->andReturn(['information_schema', 'performance_schema', 'mysql', 'sys', 'app_database', 'test_database']);

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')
        ->once()
        ->with('SHOW DATABASES')
        ->andReturn($pdoStatement);

    $db = Mockery::mock(MysqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->once()->andReturn($pdo);
    $db->setConfig(['host' => 'db.local', 'port' => 3306, 'user' => 'root', 'pass' => 'secret', 'database' => '']);

    $databases = $db->listDatabases();

    expect($databases)->toBe(['app_database', 'test_database']);
});

test('testConnection returns failure when process fails', function () {
    config(['backup.mysql_cli_type' => 'mariadb']);

    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'Access denied for user'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Access denied');
});
