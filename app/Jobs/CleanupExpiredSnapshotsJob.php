<?php

namespace App\Jobs;

use App\Services\Backup\SnapshotCleanupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupExpiredSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public bool $dryRun = false
    ) {
        $this->onQueue('backups');
    }

    public function handle(SnapshotCleanupService $service): void
    {
        $service->run($this->dryRun);
    }
}
