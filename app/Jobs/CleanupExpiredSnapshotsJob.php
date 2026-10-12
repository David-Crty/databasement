<?php

namespace App\Jobs;

use App\Actions\Snapshot\ApplyRetentionPoliciesAction;
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

    public function handle(): void
    {
        ApplyRetentionPoliciesAction::run($this->dryRun);
    }
}
