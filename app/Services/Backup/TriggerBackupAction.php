<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Snapshot;
use Illuminate\Validation\ValidationException;

class TriggerBackupAction
{
    public function __construct(
        private BackupJobFactory $backupJobFactory,
        private DispatchBackupAction $dispatchBackup,
        private DispatchDiscoveryAction $dispatchDiscovery,
    ) {}

    /**
     * Trigger one backup configuration.
     *
     * @return array{snapshots: Snapshot[], message: string}
     *
     * @throws ValidationException
     */
    public function execute(Backup $backup, ?int $triggeredByUserId = null): array
    {
        $server = $backup->databaseServer;

        $snapshots = $this->backupJobFactory->createSnapshots(
            backup: $backup,
            method: 'manual',
            triggeredByUserId: $triggeredByUserId,
        );

        // Agent-backed servers with all/pattern mode return empty snapshots —
        // dispatch a discovery job so the agent can list databases first.
        if (empty($snapshots) && $server->agent_id) {
            $this->dispatchDiscovery->execute($backup, 'manual', $triggeredByUserId);

            return [
                'snapshots' => [],
                'message' => __('Database discovery dispatched to agent. Backups will start once databases are discovered.'),
            ];
        }

        foreach ($snapshots as $snapshot) {
            $this->dispatchBackup->execute($snapshot);
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
