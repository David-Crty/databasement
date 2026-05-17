<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SchedulerRestarter
{
    /**
     * Restart the Supervisor-managed schedule-run program so newly persisted
     * schedules are picked up. Returns true on success, false on failure.
     */
    public function restart(): bool
    {
        $result = Process::timeout(10)->run('supervisorctl -c /config/supervisord.conf restart schedule-run');

        if ($result->failed()) {
            Log::warning('Failed to restart schedule-run', [
                'exit_code' => $result->exitCode(),
                'error' => $result->errorOutput(),
            ]);

            return false;
        }

        Log::info('Scheduler restarted successfully.');

        return true;
    }
}
