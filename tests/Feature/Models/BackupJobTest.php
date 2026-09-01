<?php

use App\Models\BackupJob;

test('markFailed closes out any command log still marked running', function () {
    $backupJob = BackupJob::create([
        'status' => 'running',
    ]);

    $backupJob->startCommandLog('pg_dump --clean --if-exists --no-owner');

    $backupJob->markFailed(new Exception('SSH tunnel dropped mid-command'));

    $logs = $backupJob->fresh()->getLogs();

    expect($logs)->toHaveCount(1)
        ->and($logs[0]['status'])->toBe('failed');
});

test('markFailed leaves already-finished command logs untouched', function () {
    $backupJob = BackupJob::create([
        'status' => 'running',
    ]);

    $index = $backupJob->startCommandLog('pg_dump --clean --if-exists --no-owner');
    $backupJob->updateCommandLog($index, [
        'status' => 'completed',
        'exit_code' => 0,
    ]);

    $backupJob->markFailed(new Exception('post-script failed'));

    $logs = $backupJob->fresh()->getLogs();

    expect($logs[0]['status'])->toBe('completed')
        ->and($logs[0]['exit_code'])->toBe(0);
});
