<?php

use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
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

test('dump builds correct command with skip_ssl by default', function () {
    $result = $this->db->dump('/tmp/dump.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("mariadb-dump --single-transaction --routines --add-drop-table --hex-blob --quote-names --skip_ssl --host='db.local' --port='3306' --user='root' --password='secret' 'myapp' > '/tmp/dump.sql'");
});

test('dump uses ssl-verify-server-cert=0 when ssl_enabled is true', function () {
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'ssl_enabled' => true,
    ]);

    $result = $db->dump('/tmp/dump.sql');

    expect($result->command)
        ->toContain('--ssl --ssl-verify-server-cert=0')
        ->not->toContain('--skip_ssl');
});

test('dump includes extra dump flags', function () {
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'dump_flags' => '--no-tablespaces --column-statistics=0',
    ]);

    $result = $db->dump('/tmp/dump.sql');

    // Flags must appear before the database name (mariadb-dump treats post-db args as table names)
    expect($result->command)->toContain("'--no-tablespaces' '--column-statistics=0' 'myapp'")
        ->and($result->command)->toEndWith("> '/tmp/dump.sql'");
});

/**
 * A handler on a live server reporting $version, or an unreadable one for null.
 * $mysqlClient says whether the image ships Oracle's client, which only the
 * Docker image does.
 */
function mysqlDatabaseReportingVersion(?string $version, bool $mysqlClient = true, array $extraConfig = []): MysqlDatabase
{
    $pdo = Mockery::mock(PDO::class);

    if ($version === null) {
        $pdo->shouldReceive('query')->andThrow(new PDOException('server has gone away'));
    } else {
        $statement = Mockery::mock(\PDOStatement::class);
        $statement->shouldReceive('fetchColumn')->andReturn($version);
        $pdo->shouldReceive('query')->with('SELECT VERSION()')->andReturn($statement);
    }

    $db = Mockery::mock(MysqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->andReturn($pdo);
    $db->shouldReceive('mysqlClientAvailable')->andReturn($mysqlClient);
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'probe_server_version' => true,
        ...$extraConfig,
    ]);

    return $db;
}

test('dump keeps the MariaDB client for the servers it can read', function (?string $version) {
    $result = mysqlDatabaseReportingVersion($version)->dump('/tmp/dump.sql');

    expect($result->command)->toStartWith('mariadb-dump ')
        ->and($result->command)->toContain('--routines')
        ->and($result->command)->toContain('--skip_ssl')
        ->and($result->log)->toBeNull();
})->with([
    'MariaDB on the oldest version it handles' => ['10.2.44-MariaDB'],
    'current MariaDB' => ['11.4.12-MariaDB-ubu2404'],
    'unreadable version' => [null],
]);

test("dump uses Oracle's client for the servers the MariaDB one cannot read", function (string $version) {
    $result = mysqlDatabaseReportingVersion($version)->dump('/tmp/dump.sql');

    expect($result->command)->toStartWith('/opt/mysql-client/bin/mysqldump ')
        ->and($result->command)->toContain('--routines')
        ->and($result->command)->toContain('--ssl-mode=DISABLED')
        ->and($result->command)->not->toContain('--skip_ssl')
        ->and($result->log)->toBeNull();
})->with([
    // mariadb-dump reads MySQL's YY.M version as a MariaDB one (#494), and on
    // 5.5 it asks information_schema for a column that arrived in 5.7 (#617).
    'MySQL 5.5' => ['5.5.62-0ubuntu0.14.04.1'],
    'MySQL 8' => ['8.4.11'],
    'MySQL on the pre-2026 scheme' => ['9.7.2'],
    'MySQL on the YY.M scheme' => ['26.7.0'],
    // information_schema.columns.generation_expression only exists from 10.2 (#617).
    'MariaDB below 10.2' => ['10.1.48-MariaDB-1~bionic'],
]);

test("dump uses Oracle's ssl-mode=REQUIRED when ssl_enabled is true", function () {
    $db = mysqlDatabaseReportingVersion('8.4.11', extraConfig: ['ssl_enabled' => true]);

    expect($db->dump('/tmp/dump.sql')->command)
        ->toContain('--ssl-mode=REQUIRED')
        ->not->toContain('--ssl --ssl-verify-server-cert=0');
});

test('dump trusts a supplied server version instead of connecting', function (string $version, string $expectedBinary) {
    $db = Mockery::mock(MysqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldNotReceive('createPdo');
    $db->shouldReceive('mysqlClientAvailable')->andReturn(true);
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'server_version' => $version,
    ]);

    expect($db->dump('/tmp/dump.sql')->command)->toStartWith($expectedBinary);
})->with([
    'MySQL' => ['8.4.11', '/opt/mysql-client/bin/mysqldump '],
    'MariaDB' => ['11.4.12-MariaDB', 'mariadb-dump '],
]);

// Without Oracle's client — a native install, or an image predating it — MySQL
// servers stay on mariadb-dump, which clears its own >= 10.3 package gate on
// the YY.M scheme and dies on SHOW PACKAGE STATUS unless --routines goes (#494).
test('dump drops --routines for MySQL versions that trip the MariaDB package check', function () {
    $result = mysqlDatabaseReportingVersion('26.7.0', mysqlClient: false)->dump('/tmp/dump.sql');

    expect($result->command)->not->toContain('--routines')
        ->and($result->command)->toContain('mariadb-dump --single-transaction --add-drop-table')
        ->and($result->log?->level)->toBe('warning')
        ->and($result->log?->message)->toContain('26.7.0');
});

test("restore feeds the dump to Oracle's client on stdin", function () {
    $result = mysqlDatabaseReportingVersion('8.4.11')->restore('/tmp/restore.sql');

    expect($result->command)->toBe(
        "/opt/mysql-client/bin/mysql --host='db.local' --port='3306' --user='root' --password='secret' --ssl-mode=DISABLED 'myapp' < '/tmp/restore.sql'"
    );
});

test('restore keeps the MariaDB client for a MariaDB server', function () {
    $result = mysqlDatabaseReportingVersion('11.4.12-MariaDB')->restore('/tmp/restore.sql');

    expect($result->command)->toStartWith('mariadb ')
        ->and($result->command)->toContain("-e 'source /tmp/restore.sql'");
});

test('dump does not probe the server when the config is not for a live server', function () {
    $db = Mockery::mock(MysqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldNotReceive('createPdo');
    $db->setConfig(['host' => 'hostname', 'port' => 3306, 'user' => 'user', 'pass' => '***', 'database' => 'dbname']);

    expect($db->dump('/path/to/output')->command)->toContain('--routines');
});

test('restore builds correct command with skip_ssl by default', function () {
    $result = $this->db->restore('/tmp/restore.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("mariadb --host='db.local' --port='3306' --user='root' --password='secret' --skip_ssl 'myapp' -e 'source /tmp/restore.sql'");
});

test('restore uses ssl-verify-server-cert=0 when ssl_enabled is true', function () {
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'ssl_enabled' => true,
    ]);

    $result = $db->restore('/tmp/restore.sql');

    expect($result->command)
        ->toContain('--ssl --ssl-verify-server-cert=0')
        ->not->toContain('--skip_ssl');
});

test('testConnection returns success when process succeeds', function () {
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
    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'Access denied for user'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Access denied');
});

test('dump refuses a flag that redirects where the client writes', function () {
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'dump_flags' => '--result-file=/app/public/shell.php',
    ]);

    expect(fn () => $db->dump('/tmp/dump.sql'))
        ->toThrow(App\Exceptions\Backup\DatabaseDumpException::class, '--result-file=/app/public/shell.php');
});
