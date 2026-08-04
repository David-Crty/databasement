<?php

namespace App\Support;

use App\Facades\AppConfig;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Timing window that keeps a queued backup/restore from running twice.
 *
 * Laravel hands a reserved job to another worker once `retry_after` elapses,
 * whether or not it is still running. The framework default is 90 seconds while
 * `backup.job_timeout` defaults to 7200, so any dump longer than 90 seconds was
 * picked up a second time mid-run. The three values below must keep this order:
 *
 *     job timeout  <  overlap lock expiry  <  retry_after
 *
 * The lock outlives the job so a running dump can never be duplicated, and
 * expires before `retry_after` so that a genuine retry (worker killed, lock
 * never released) is still allowed to run.
 *
 * A worker resolves `retry_after` once, when it boots: the value is baked into
 * the queue connection and later config changes do not reach it. Editing
 * `backup.job_timeout` therefore signals a worker restart (see
 * App\Livewire\Configuration\Form::saveBackup), otherwise a raised timeout would
 * leave running workers with a retry_after below it.
 */
final class QueueTimeouts
{
    /** Seconds added to the job timeout before a reserved job may be handed to another worker. */
    public const int RETRY_GRACE_SECONDS = 300;

    /** Seconds added to the job timeout before the overlap lock expires on its own. */
    public const int LOCK_GRACE_SECONDS = 60;

    /**
     * Queue connections whose `retry_after` governs re-delivery of reserved jobs.
     *
     * SQS is absent on purpose: its equivalent (the visibility timeout) lives on
     * the AWS queue itself and cannot be set from here.
     *
     * @var list<string>
     */
    private const array CONNECTIONS = ['database', 'redis', 'beanstalkd'];

    /**
     * Raise the configured `retry_after` above the longest a job may run.
     *
     * An explicitly configured value is never lowered, only raised to the safe
     * minimum.
     */
    public static function apply(): void
    {
        $minimum = self::retryAfter();

        foreach (self::CONNECTIONS as $connection) {
            $key = "queue.connections.{$connection}.retry_after";
            $configured = config($key);

            if ($configured === null) {
                continue;
            }

            config([$key => max((int) $configured, $minimum)]);
        }
    }

    /**
     * Guard that stops a second copy of a job running alongside the first.
     *
     * The duplicate is dropped rather than released: releasing it would only run
     * the same dump or restore again once the original finished.
     */
    public static function overlapGuard(string $key, int $jobTimeout): WithoutOverlapping
    {
        return (new WithoutOverlapping($key))
            ->expireAfter($jobTimeout + self::LOCK_GRACE_SECONDS)
            ->dontRelease();
    }

    /**
     * Earliest a reserved job may be re-delivered, in seconds.
     */
    public static function retryAfter(): int
    {
        return self::jobTimeout() + self::RETRY_GRACE_SECONDS;
    }

    private static function jobTimeout(): int
    {
        return (int) AppConfig::get('backup.job_timeout');
    }
}
