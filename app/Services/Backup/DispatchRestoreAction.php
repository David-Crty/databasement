<?php

namespace App\Services\Backup;

use App\Enums\AgentJobType;
use App\Jobs\ProcessRestoreJob;
use App\Models\AgentJob;
use App\Models\Restore;
use App\Services\Agent\AgentJobPayloadBuilder;
use RuntimeException;

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

        // Without a payload no agent job exists to fail the restore later, so
        // fail it here rather than leave it pending until it times out.
        try {
            $payload = $this->payloadBuilder->buildRestore($restore);
        } catch (RuntimeException $e) {
            $restore->job->log("Restore failed: {$e->getMessage()}", 'error');
            $restore->job->markFailed($e);

            throw $e;
        }

        AgentJob::enqueue(AgentJobType::Restore, $targetServer->id, $payload, [
            'restore_id' => $restore->id,
        ]);
    }
}
