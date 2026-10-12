<?php

namespace App\Exceptions\Backup;

/**
 * A user cancelled the job while it ran: whatever runs it must stop, and the
 * job is not retried.
 */
class JobCancelledException extends JobRevokedException
{
    public function __construct()
    {
        parent::__construct('The job was cancelled.');
    }
}
