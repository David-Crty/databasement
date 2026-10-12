<?php

namespace App\Jobs;

use App\Actions\Snapshot\VerifySnapshotFilesAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifySnapshotFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $organizationId = null
    ) {
        $this->onQueue('backups');
    }

    public function handle(): void
    {
        VerifySnapshotFilesAction::run($this->organizationId);
    }
}
