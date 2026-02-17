<?php

namespace App\Console\Commands;

use App\Jobs\CleanupExpiredSnapshotsJob;
use Illuminate\Console\Command;

class CleanupExpiredSnapshots extends Command
{
    protected $signature = 'snapshots:cleanup';

    protected $description = 'Delete snapshots older than the configured retention period';

    public function handle(): int
    {
        CleanupExpiredSnapshotsJob::dispatch();

        $this->info('Snapshot cleanup job dispatched.');

        return self::SUCCESS;
    }
}
