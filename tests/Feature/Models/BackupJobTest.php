<?php

use App\Models\BackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('markFailed finalizes a command log left dangling at running status', function () {
    $job = BackupJob::create(['status' => 'running']);

    $index = $job->startCommandLog('pg_dump ...');
    expect($job->getLogs()[$index]['status'])->toBe('running');

    $job->markFailed(new Exception('worker was killed mid-command'));

    $job->refresh();

    expect($job->status->value)->toBe('failed')
        ->and($job->getLogs()[$index]['status'])->toBe('failed');
});

test('markFailed leaves already-finalized command logs untouched', function () {
    $job = BackupJob::create(['status' => 'running']);

    $index = $job->startCommandLog('mysqldump ...');
    $job->updateCommandLog($index, ['status' => 'completed', 'exit_code' => 0]);

    $job->markFailed(new Exception('a later step failed'));

    $job->refresh();

    expect($job->getLogs()[$index]['status'])->toBe('completed');
});
