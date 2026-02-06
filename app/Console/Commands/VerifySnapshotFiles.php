<?php

namespace App\Console\Commands;

use App\Jobs\VerifySnapshotFileJob;
use Illuminate\Console\Command;

class VerifySnapshotFiles extends Command
{
    protected $signature = 'snapshots:verify-files';

    protected $description = 'Verify that backup files still exist on their storage volumes';

    public function handle(): int
    {
        VerifySnapshotFileJob::dispatch();

        $this->info('Snapshot file verification job dispatched.');

        return self::SUCCESS;
    }
}
