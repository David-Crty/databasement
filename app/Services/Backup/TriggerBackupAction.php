<?php

namespace App\Services\Backup;

use App\Jobs\ProcessBackupJob;
use App\Models\Backup;
use App\Models\Snapshot;

class TriggerBackupAction
{
    public function __construct(
        private BackupJobFactory $backupJobFactory,
    ) {}

    /**
     * Trigger one backup configuration.
     *
     * @return array{snapshots: Snapshot[], message: string}
     */
    public function execute(Backup $backup, ?int $triggeredByUserId = null): array
    {
        $snapshots = $this->backupJobFactory->createSnapshots(
            backup: $backup,
            method: 'manual',
            triggeredByUserId: $triggeredByUserId,
        );

        foreach ($snapshots as $snapshot) {
            ProcessBackupJob::dispatch($snapshot->id);
        }

        $count = count($snapshots);
        $message = $count === 1
            ? 'Backup started successfully!'
            : "{$count} database backups started successfully!";

        return [
            'snapshots' => $snapshots,
            'message' => $message,
        ];
    }
}
