<?php

use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Jobs\ProcessRestoreJob;
use App\Support\QueueTimeouts;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

/**
 * Hold the overlap lock the way the worker running the original copy would.
 */
$holdOverlapLock = function (ProcessBackupJob|ProcessRestoreJob $job): void {
    /** @var WithoutOverlapping $middleware */
    $middleware = $job->middleware()[0];

    Cache::lock($middleware->getLockKey($job), 60)->get();
};

/**
 * Run a job through its overlap middleware and report whether it executed.
 */
$runThroughOverlapGuard = function (ProcessBackupJob|ProcessRestoreJob $job): bool {
    $executed = false;

    $job->middleware()[0]->handle($job, function () use (&$executed) {
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

    // A lock shorter than the job would let a duplicate through mid-run; one
    // longer than retry_after would swallow the retry after a killed worker.
    expect(QueueTimeouts::lockExpiry(3600))->toBeGreaterThan(3600)
        ->and(QueueTimeouts::lockExpiry(3600))->toBeLessThan(QueueTimeouts::retryAfter());
});

test('a backup re-delivered while the original is still running is dropped', function () use ($holdOverlapLock, $runThroughOverlapGuard) {
    $holdOverlapLock(new ProcessBackupJob('snapshot-id'));

    expect($runThroughOverlapGuard(new ProcessBackupJob('snapshot-id')))->toBeFalse();
});

test('a restore re-delivered while the original is still running is dropped', function () use ($holdOverlapLock, $runThroughOverlapGuard) {
    $holdOverlapLock(new ProcessRestoreJob('restore-id'));

    expect($runThroughOverlapGuard(new ProcessRestoreJob('restore-id')))->toBeFalse();
});

test('backups of different snapshots still run concurrently', function () use ($holdOverlapLock, $runThroughOverlapGuard) {
    $holdOverlapLock(new ProcessBackupJob('snapshot-one'));

    expect($runThroughOverlapGuard(new ProcessBackupJob('snapshot-two')))->toBeTrue();
});
