<?php

namespace App\Services\Backup;

use App\Enums\AgentJobType;
use App\Jobs\ProcessBackupJob;
use App\Models\AgentJob;
use App\Models\Snapshot;
use App\Services\Agent\AgentJobPayloadBuilder;

class DispatchBackupAction
{
    public function __construct(
        private AgentJobPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * Hand a created snapshot to whatever can reach its server: the server's
     * remote agent, or the app's own queue.
     */
    public function execute(Snapshot $snapshot): void
    {
        $server = $snapshot->databaseServer;

        if ($server->agent_id === null) {
            ProcessBackupJob::dispatch($snapshot->id);

            return;
        }

        AgentJob::enqueue(AgentJobType::Backup, $server->id, $this->payloadBuilder->buildBackup($snapshot), [
            'snapshot_id' => $snapshot->id,
        ]);
    }
}
