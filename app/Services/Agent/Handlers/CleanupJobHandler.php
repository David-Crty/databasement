<?php

namespace App\Services\Agent\Handlers;

use App\Exceptions\Backup\SnapshotFilesNotDeletedException;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Services\Backup\DeleteSnapshotAction;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupJobHandler implements AgentJobHandler
{
    public function __construct(
        private DeleteSnapshotAction $deleteSnapshot,
    ) {}

    /**
     * A file already gone counts as removed, so running the job again is harmless.
     */
    public function maxAttempts(): int
    {
        return 3;
    }

    public function leaseSeconds(): int
    {
        return 300;
    }

    public function trackedJob(AgentJob $agentJob): ?BackupJob
    {
        return null;
    }

    public function completionRules(): array
    {
        return self::fileResultRules();
    }

    public function complete(AgentJob $agentJob, array $result): array
    {
        $agentJob->markCompleted();

        $this->finishDeletion($agentJob, $result, 'The agent did not report on this file.');

        return [];
    }

    public function failureRules(): array
    {
        return self::fileResultRules();
    }

    public function fail(AgentJob $agentJob, Throwable $exception, array $result): void
    {
        $this->finishDeletion($agentJob, $result, $exception->getMessage());
    }

    /**
     * Forget the copies the agent removed and delete the snapshot when none
     * is left. A copy the agent did not report as removed keeps the snapshot
     * with the reason recorded, and is retried by the next deletion.
     *
     * @param  array<string, mixed>  $result
     */
    private function finishDeletion(AgentJob $agentJob, array $result, string $unreportedError): void
    {
        $snapshot = $agentJob->snapshot;

        if ($snapshot === null) {
            return;
        }

        $outcomes = array_fill_keys(array_column($agentJob->payload['files'] ?? [], 'id'), $unreportedError);

        foreach ($result['files'] ?? [] as $file) {
            if (array_key_exists($file['id'], $outcomes)) {
                $outcomes[$file['id']] = $file['error'] ?? null;
            }
        }

        $this->deleteSnapshot->recordRemovals($snapshot, $outcomes);

        try {
            $this->deleteSnapshot->deleteUnlessFilesRemain($snapshot);
        } catch (SnapshotFilesNotDeletedException $e) {
            Log::warning($e->getMessage());
        }
    }

    /**
     * One outcome per copy of the payload: a null error once its file is gone.
     *
     * @return array<string, string>
     */
    private static function fileResultRules(): array
    {
        return [
            'files' => 'sometimes|array',
            'files.*.id' => 'required|string|max:26',
            'files.*.error' => 'nullable|string|max:10000',
        ];
    }
}
