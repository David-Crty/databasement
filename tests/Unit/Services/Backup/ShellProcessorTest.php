<?php

use App\Services\Backup\ShellProcessor;

test('sanitizes --password= format', function () {
    $processor = new ShellProcessor;

    $result = $processor->sanitizeCommand('mysqldump --password=secret123 dbname');

    expect($result)->toContain('--password=***')
        ->not->toContain('secret123');
});

test('sanitizes quoted --password= format', function () {
    $processor = new ShellProcessor;

    $result = $processor->sanitizeCommand("mysqldump --password='secret123' dbname");

    expect($result)->toContain('--password=***')
        ->not->toContain('secret123');
});

test('sanitizes -p shorthand format', function () {
    $processor = new ShellProcessor;

    $result = $processor->sanitizeCommand('mysqldump -psecret123 dbname');

    expect($result)->toContain('-p***')
        ->not->toContain('secret123');
});

test('sanitizes PGPASSWORD env var', function () {
    $processor = new ShellProcessor;

    $result = $processor->sanitizeCommand('PGPASSWORD=secret123 pg_dump dbname');

    expect($result)->toContain('PGPASSWORD=***')
        ->not->toContain('secret123');
});

test('does not sanitize hostname containing -p', function () {
    $processor = new ShellProcessor;

    $result = $processor->sanitizeCommand("mysqldump --host='mysql-production.example.com' dbname");

    expect($result)->toContain('mysql-production.example.com');
});

test('does not sanitize --port option', function () {
    $processor = new ShellProcessor;

    $result = $processor->sanitizeCommand('mysqldump --port=3306 dbname');

    expect($result)->toContain('--port=3306');
});

test('sanitizes realistic mariadb-dump command', function () {
    $processor = new ShellProcessor;

    $command = "mariadb-dump --routines --skip_ssl --host='mysql-production.example.com' --port='3306' --user='root' --password='supersecret' 'mydb' > '/tmp/backup.sql'";
    $result = $processor->sanitizeCommand($command);

    expect($result)
        ->toContain('mysql-production.example.com')
        ->toContain("--port='3306'")
        ->toContain("--user='root'")
        ->toContain('--password=***')
        ->toContain("'mydb'")
        ->not->toContain('supersecret');
});

test('sanitizes realistic pg_dump command', function () {
    $processor = new ShellProcessor;

    $command = 'PGPASSWORD=supersecret pg_dump -h postgres-production.example.com -p 5432 -U admin mydb > /tmp/backup.sql';
    $result = $processor->sanitizeCommand($command);

    expect($result)
        ->toContain('PGPASSWORD=***')
        ->toContain('postgres-production.example.com')
        ->toContain('-p 5432')
        ->toContain('-U admin')
        ->not->toContain('supersecret');
});
