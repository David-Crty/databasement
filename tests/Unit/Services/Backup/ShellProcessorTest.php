<?php

use App\Services\Backup\ShellProcessor;

test('sanitizes sensitive patterns', function (string $input, string $expectedToContain, string $secretToRedact) {
    $processor = new ShellProcessor;

    $result = $processor->sanitize($input);

    expect($result)
        ->toContain($expectedToContain)
        ->not->toContain($secretToRedact);
})->with([
    '--password= format' => ['mysqldump --password=secret123 dbname', '--password=***', 'secret123'],
    'quoted --password= format' => ["mysqldump --password='secret123' dbname", '--password=***', 'secret123'],
    '-p shorthand format' => ['mysqldump -psecret123 dbname', '-p***', 'secret123'],
    'PGPASSWORD env var' => ['PGPASSWORD=secret123 pg_dump dbname', 'PGPASSWORD=***', 'secret123'],
    'MYSQL_PWD env var' => ['MYSQL_PWD=secret123 mysqldump failed', 'MYSQL_PWD=***', 'secret123'],
]);

test('preserves non-sensitive patterns', function (string $input, string $expectedToContain) {
    $processor = new ShellProcessor;

    $result = $processor->sanitize($input);

    expect($result)->toContain($expectedToContain);
})->with([
    'hostname containing -p' => ["mysqldump --host='mysql-production.example.com' dbname", 'mysql-production.example.com'],
    '--port option' => ['mysqldump --port=3306 dbname', '--port=3306'],
    '-p with space (port flag)' => ['pg_dump -p 5432 dbname', '-p 5432'],
]);

test('sanitizes realistic mariadb-dump command', function () {
    $processor = new ShellProcessor;

    $command = "mariadb-dump --host='mysql-production.example.com' --port='3306' --user='root' --password='supersecret' 'mydb'";
    $result = $processor->sanitize($command);

    expect($result)
        ->toContain('mysql-production.example.com')
        ->toContain("--port='3306'")
        ->toContain('--password=***')
        ->not->toContain('supersecret');
});

test('sanitizes realistic pg_dump command', function () {
    $processor = new ShellProcessor;

    $command = 'PGPASSWORD=supersecret pg_dump -h postgres-production.example.com -p 5432 -U admin mydb';
    $result = $processor->sanitize($command);

    expect($result)
        ->toContain('PGPASSWORD=***')
        ->toContain('postgres-production.example.com')
        ->toContain('-p 5432')
        ->not->toContain('supersecret');
});

test('process method returns unsanitized output', function () {
    $processor = new ShellProcessor;

    // Mock a command that outputs sensitive data
    $command = 'echo "Connection failed: mysql://user:secret123@localhost"';

    $result = $processor->process($command);

    // The return value should contain the actual output (not sanitized)
    expect($result)->toContain('secret123');
});

test('process method logs sanitized output', function () {
    $processor = new ShellProcessor;
    $logger = \Mockery::mock(\App\Models\BackupJob::class);

    // Command contains PGPASSWORD which should be sanitized
    $logger->shouldReceive('startCommandLog')
        ->once()
        ->with(\Mockery::on(fn ($cmd) => str_contains($cmd, 'PGPASSWORD=***') && ! str_contains($cmd, 'secret123')))
        ->andReturn(0);

    // Verify updateCommandLog also receives sanitized output
    $logger->shouldReceive('updateCommandLog')
        ->atLeast()->once()
        ->with(0, \Mockery::on(fn ($data) => ! str_contains($data['output'] ?? '', 'secret123')));

    $processor->setLogger($logger);

    // Run command with PGPASSWORD which matches sanitization pattern
    $processor->process('PGPASSWORD=secret123 echo "test"');
});
