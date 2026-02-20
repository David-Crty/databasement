<?php

namespace App\Services\Backup;

use App\Jobs\ProcessBackupJob;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Agent\AgentJobPayloadBuilder;
use RuntimeException;

class TriggerBackupAction
{
    public function __construct(
        private BackupJobFactory $backupJobFactory,
        private AgentJobPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * Trigger a backup for the given database server.
     *
     * @return array{snapshots: Snapshot[], message: string}
     *
     * @throws RuntimeException
     */
    public function execute(DatabaseServer $server, ?int $triggeredByUserId = null): array
    {
        if (! $server->backup) {
            throw new RuntimeException(
                'No backup configuration found for this database server.'
            );
        }

        $snapshots = $this->backupJobFactory->createSnapshots(
            server: $server,
            method: 'manual',
            triggeredByUserId: $triggeredByUserId
        );

        if ($server->agent_id) {
            $this->dispatchToAgent($snapshots);
        } else {
            $this->dispatchToQueue($snapshots);
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

    /**
     * Dispatch snapshots to the queue for local execution.
     *
     * @param  Snapshot[]  $snapshots
     */
    private function dispatchToQueue(array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            ProcessBackupJob::dispatch($snapshot->id);
        }
    }

    /**
     * Create AgentJob records for remote agent execution.
     *
     * @param  Snapshot[]  $snapshots
     */
    private function dispatchToAgent(array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            AgentJob::create([
                'snapshot_id' => $snapshot->id,
                'status' => AgentJob::STATUS_PENDING,
                'payload' => $this->payloadBuilder->build($snapshot),
            ]);
        }
    }
}
