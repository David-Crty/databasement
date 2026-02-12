<?php

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

test('getDumpCommandLine builds correct command', function (string $cliType, string $expectedCommand) {
    config(['backup.mysql_cli_type' => $cliType]);

    expect($this->db->getDumpCommandLine('/tmp/dump.sql'))->toBe($expectedCommand);
})->with([
    'mariadb' => ['mariadb', "mariadb-dump --single-transaction --routines --add-drop-table --complete-insert --hex-blob --quote-names --skip_ssl --host='db.local' --port='3306' --user='root' --password='secret' 'myapp' > '/tmp/dump.sql'"],
    'mysql' => ['mysql', "mysqldump --single-transaction --routines --add-drop-table --complete-insert --hex-blob --quote-names --host='db.local' --port='3306' --user='root' --password='secret' 'myapp' > '/tmp/dump.sql'"],
]);

test('getRestoreCommandLine builds correct command', function (string $cliType, string $expectedCommand) {
    config(['backup.mysql_cli_type' => $cliType]);

    expect($this->db->getRestoreCommandLine('/tmp/restore.sql'))->toBe($expectedCommand);
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

test('testConnection returns failure when process fails', function () {
    config(['backup.mysql_cli_type' => 'mariadb']);

    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'Access denied for user'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Access denied');
});
