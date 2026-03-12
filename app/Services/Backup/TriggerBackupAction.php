<?php

namespace App\Services\Backup;

use App\Jobs\ProcessBackupJob;
use App\Models\AgentJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Agent\AgentJobPayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * @throws ValidationException
     */
    public function execute(DatabaseServer $server, ?int $triggeredByUserId = null): array
    {
        if (! $server->backup) {
            throw ValidationException::withMessages([
                'server' => 'No backup configuration found for this database server.',
            ]);
        }

        $snapshots = $this->backupJobFactory->createSnapshots(
            server: $server,
            method: 'manual',
            triggeredByUserId: $triggeredByUserId
        );

        // Agent-backed servers with all/pattern mode return empty snapshots —
        // dispatch a discovery job so the agent can list databases first.
        if (empty($snapshots) && $server->agent_id) {
            $this->dispatchDiscoveryJob($server, 'manual', $triggeredByUserId);

            return [
                'snapshots' => [],
                'message' => __('Database discovery dispatched to agent. Backups will start once databases are discovered.'),
            ];
        }

        if ($server->agent_id) {
            $this->dispatchToAgent($server, $snapshots);
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
    private function dispatchToAgent(DatabaseServer $server, array $snapshots): void
    {
        DB::transaction(function () use ($server, $snapshots): void {
            foreach ($snapshots as $snapshot) {
                AgentJob::create([
                    'type' => AgentJob::TYPE_BACKUP,
                    'database_server_id' => $server->id,
                    'snapshot_id' => $snapshot->id,
                    'status' => AgentJob::STATUS_PENDING,
                    'payload' => $this->payloadBuilder->build($snapshot),
                ]);
            }
        });
    }

    /**
     * Dispatch a discovery job for an agent-backed server.
     *
     * @param  'manual'|'scheduled'  $method
     */
    private function dispatchDiscoveryJob(DatabaseServer $server, string $method, ?int $triggeredByUserId): void
    {
        AgentJob::create([
            'type' => AgentJob::TYPE_DISCOVER,
            'database_server_id' => $server->id,
            'snapshot_id' => null,
            'status' => AgentJob::STATUS_PENDING,
            'payload' => $this->payloadBuilder->buildDiscovery($server, $method, $triggeredByUserId),
        ]);
    }
}
