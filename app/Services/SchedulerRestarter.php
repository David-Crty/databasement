<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
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
        try {
            $result = Process::timeout(10)->run('supervisorctl -c /config/supervisord.conf restart schedule-run');
        } catch (ProcessTimedOutException $e) {
            Log::warning('Failed to restart schedule-run: timed out', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

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
