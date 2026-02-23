<?php

use App\Services\Backup\InMemoryBackupLogger;

test('log stores message with level and timestamp', function () {
    $logger = new InMemoryBackupLogger;

    $logger->log('Backup started', 'info');

    $logs = $logger->getLogs();
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['type'])->toBe('log')
        ->and($logs[0]['level'])->toBe('info')
        ->and($logs[0]['message'])->toBe('Backup started')
        ->and($logs[0])->not->toHaveKey('context');
});

test('log stores context when provided', function () {
    $logger = new InMemoryBackupLogger;

    $logger->log('Transfer done', 'success', ['file_size' => '10MB']);

    $logs = $logger->getLogs();
    expect($logs[0]['context'])->toBe(['file_size' => '10MB']);
});

test('logCommand stores command with output and exit code', function () {
    $logger = new InMemoryBackupLogger;

    $logger->logCommand('mysqldump mydb', 'dumped 100 rows', 0, microtime(true) - 1.5);

    $logs = $logger->getLogs();
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['type'])->toBe('command')
        ->and($logs[0]['command'])->toBe('mysqldump mydb')
        ->and($logs[0]['output'])->toBe('dumped 100 rows')
        ->and($logs[0]['exit_code'])->toBe(0)
        ->and($logs[0]['duration_ms'])->toBeGreaterThan(0);
});

test('logCommand stores null duration when no start time given', function () {
    $logger = new InMemoryBackupLogger;

    $logger->logCommand('echo hello');

    $logs = $logger->getLogs();
    expect($logs[0]['duration_ms'])->toBeNull();
});

test('startCommandLog returns index and marks as running', function () {
    $logger = new InMemoryBackupLogger;

    $index = $logger->startCommandLog('pg_dump testdb');

    expect($index)->toBe(0);

    $logs = $logger->getLogs();
    expect($logs[0]['type'])->toBe('command')
        ->and($logs[0]['command'])->toBe('pg_dump testdb')
        ->and($logs[0]['status'])->toBe('running')
        ->and($logs[0]['output'])->toBeNull();
});

test('updateCommandLog merges data into existing entry', function () {
    $logger = new InMemoryBackupLogger;

    $index = $logger->startCommandLog('mysqldump');
    $logger->updateCommandLog($index, [
        'status' => 'completed',
        'exit_code' => 0,
        'output' => 'success',
    ]);

    $logs = $logger->getLogs();
    expect($logs[$index]['status'])->toBe('completed')
        ->and($logs[$index]['exit_code'])->toBe(0)
        ->and($logs[$index]['output'])->toBe('success')
        ->and($logs[$index]['command'])->toBe('mysqldump');
});

test('updateCommandLog ignores invalid index', function () {
    $logger = new InMemoryBackupLogger;

    $logger->updateCommandLog(999, ['status' => 'completed']);

    expect($logger->getLogs())->toBeEmpty();
});

test('flush returns only new entries since last flush', function () {
    $logger = new InMemoryBackupLogger;

    $logger->log('first');
    $logger->log('second');

    $batch1 = $logger->flush();
    expect($batch1)->toHaveCount(2);

    $logger->log('third');

    $batch2 = $logger->flush();
    expect($batch2)->toHaveCount(1)
        ->and($batch2[0]['message'])->toBe('third');
});

test('flush returns empty when no new entries', function () {
    $logger = new InMemoryBackupLogger;

    $logger->log('hello');
    $logger->flush();

    expect($logger->flush())->toBeEmpty();
});

test('getLogs returns all entries regardless of flush state', function () {
    $logger = new InMemoryBackupLogger;

    $logger->log('first');
    $logger->flush();
    $logger->log('second');

    expect($logger->getLogs())->toHaveCount(2);
});
