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
     * Agent-backed servers in all/pattern mode get no snapshots yet: the agent
     * lists the databases first, through a discovery job. `discovery` is null
     * when none was needed, false when one for this backup was already in flight.
     *
     * @param  'manual'|'scheduled'  $method
     * @return array{snapshots: Snapshot[], discovery: bool|null, message: string}
     *
     * @throws ValidationException
     */
    public function execute(Backup $backup, ?int $triggeredByUserId = null, string $method = 'manual'): array
    {
        $server = $backup->databaseServer;

        $snapshots = $this->backupJobFactory->createSnapshots(
            backup: $backup,
            method: $method,
            triggeredByUserId: $triggeredByUserId,
        );

        if (empty($snapshots) && $server->agent_id) {
            $dispatched = $this->dispatchDiscovery->execute($backup, $method, $triggeredByUserId);

            return [
                'snapshots' => [],
                'discovery' => $dispatched,
                'message' => $dispatched
                    ? __('Database discovery dispatched to agent. Backups will start once databases are discovered.')
                    : __('Database discovery is already running on the agent. Backups will start once databases are discovered.'),
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
            'discovery' => null,
            'message' => $message,
        ];
    }
}
