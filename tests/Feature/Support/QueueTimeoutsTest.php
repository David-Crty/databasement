<?php

use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Jobs\ProcessRestoreJob;
use App\Support\QueueTimeouts;
use Illuminate\Support\Facades\Cache;

/**
 * Hold the overlap lock as the worker running the original copy would, then
 * report whether a second delivery of the same job still executes.
 */
$duplicateRuns = function (
    ProcessBackupJob|ProcessRestoreJob $original,
    ProcessBackupJob|ProcessRestoreJob $duplicate,
): bool {
    Cache::lock($original->middleware()[0]->getLockKey($original), 60)->get();

    $executed = false;
    $duplicate->middleware()[0]->handle($duplicate, function () use (&$executed) {
        $executed = true;
    });

    return $executed;
};

test('retry_after is raised above the configured job timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);
    config([
        'queue.connections.database.retry_after' => 90,
        'queue.connections.redis.retry_after' => 90,
    ]);

    QueueTimeouts::apply();

    expect(config('queue.connections.database.retry_after'))->toBe(3900)
        ->and(config('queue.connections.redis.retry_after'))->toBe(3900);
});

test('an explicitly higher retry_after is left alone', function () {
    AppConfig::set('backup.job_timeout', 3600);
    config(['queue.connections.database.retry_after' => 20000]);

    QueueTimeouts::apply();

    expect(config('queue.connections.database.retry_after'))->toBe(20000);
});

test('the overlap lock outlives the job but expires before a retry is allowed', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $guard = QueueTimeouts::overlapGuard('snapshot-id', 3600);

    // A lock shorter than the job would let a duplicate through mid-run; one
    // longer than retry_after would swallow the retry after a killed worker.
    expect($guard->expiresAfter)->toBeGreaterThan(3600)
        ->and($guard->expiresAfter)->toBeLessThan(QueueTimeouts::retryAfter())
        ->and($guard->releaseAfter)->toBeNull();
});

test('a backup re-delivered while the original is still running is dropped', function () use ($duplicateRuns) {
    expect($duplicateRuns(new ProcessBackupJob('snapshot-id'), new ProcessBackupJob('snapshot-id')))->toBeFalse();
});

test('a restore re-delivered while the original is still running is dropped', function () use ($duplicateRuns) {
    expect($duplicateRuns(new ProcessRestoreJob('restore-id'), new ProcessRestoreJob('restore-id')))->toBeFalse();
});

test('backups of different snapshots still run concurrently', function () use ($duplicateRuns) {
    expect($duplicateRuns(new ProcessBackupJob('snapshot-one'), new ProcessBackupJob('snapshot-two')))->toBeTrue();
});
