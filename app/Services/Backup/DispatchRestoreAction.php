<?php

namespace App\Services\Backup;

use App\Jobs\ProcessRestoreJob;
use App\Models\AgentJob;
use App\Models\Restore;
use App\Services\Agent\AgentJobPayloadBuilder;

class DispatchRestoreAction
{
    public function __construct(
        private AgentJobPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * Hand a created restore to whatever can reach its target: the server's
     * remote agent, or the app's own queue.
     */
    public function execute(Restore $restore): void
    {
        $targetServer = $restore->targetServer;

        if ($targetServer->agent_id === null) {
            ProcessRestoreJob::dispatch($restore->id);

            return;
        }

        // Never retried: a restore drops and recreates the target database,
        // so a second run after a lost agent is worse than a reported failure.
        AgentJob::create([
            'type' => AgentJob::TYPE_RESTORE,
            'database_server_id' => $targetServer->id,
            'restore_id' => $restore->id,
            'status' => AgentJob::STATUS_PENDING,
            'payload' => $this->payloadBuilder->buildRestore($restore),
            'max_attempts' => 1,
        ]);
    }
}
