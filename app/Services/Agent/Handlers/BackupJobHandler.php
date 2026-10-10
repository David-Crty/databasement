<?php

namespace App\Services\Agent\Handlers;

use App\Enums\SnapshotFileStatus;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Rules\SafePath;
use App\Services\NotificationService;
use App\Support\QueueTimeouts;
use RuntimeException;
use Throwable;

class BackupJobHandler implements AgentJobHandler
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function maxAttempts(): int
    {
        return 3;
    }

    /**
     * The dump command cannot heartbeat midway, so the lease spans the whole
     * job timeout rather than expiring under a long-running dump.
     */
    public function leaseSeconds(): int
    {
        return max(1, QueueTimeouts::jobTimeout());
    }

    public function trackedJob(AgentJob $agentJob): ?BackupJob
    {
        return $agentJob->snapshot?->job;
    }

    public function completionRules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:1000', new SafePath],
            'file_size' => 'required|integer|min:0',
            'checksum' => 'nullable|string|max:255',
            ...self::volumeResultRules(),
        ];
    }

    public function complete(AgentJob $agentJob, array $result): array
    {
        $snapshot = $this->snapshot($agentJob);
        $snapshot->update([
            'filename' => $result['filename'],
            'file_size' => $result['file_size'],
        ]);

        // An empty volumes array carries no outcomes — treat it like a legacy
        // (null) payload rather than a silent success, mirroring fail().
        $this->applyVolumeResults($snapshot, ! empty($result['volumes']) ? $result['volumes'] : null);

        $agentJob->markCompleted();

        // Whole-job-fails rule: an ack that leaves failed copies (or targets a
        // legacy agent couldn't upload to) fails the backup job even though
        // the agent finished its part.
        $backupJob = $snapshot->job;
        if ($snapshot->files()->where('status', SnapshotFileStatus::Failed)->exists()) {
            $exception = new RuntimeException(
                'Some volume uploads failed. If this backup targets multiple volumes, make sure the agent is up to date — older agents only upload to the first volume.',
            );
            $backupJob->log("Backup failed: {$exception->getMessage()}", 'error');
            $backupJob->markFailed($exception);

            $this->notificationService->notifyBackupFailed($snapshot, $exception);
        } else {
            $snapshot->markCompleted($result['checksum'] ?? null);
            $backupJob->markCompleted();

            $this->notificationService->notifyBackupSuccess($snapshot);
        }

        return [];
    }

    public function failureRules(): array
    {
        return [
            'filename' => ['nullable', 'string', 'max:1000', new SafePath],
            'file_size' => 'nullable|integer|min:0',
            ...self::volumeResultRules(),
        ];
    }

    public function fail(AgentJob $agentJob, Throwable $exception, array $result): void
    {
        $snapshot = $this->snapshot($agentJob);

        // Partial failure: record the copies that did upload so their files
        // stay tracked (deletable, restorable) despite the failure.
        if (! empty($result['volumes'])) {
            if (($result['filename'] ?? '') !== '') {
                $snapshot->update([
                    'filename' => $result['filename'],
                    'file_size' => $result['file_size'] ?? 0,
                ]);
            }

            $this->applyVolumeResults($snapshot, $result['volumes']);
        }

        $snapshot->job->markFailed($exception);

        $this->notificationService->notifyBackupFailed($snapshot, $exception);
    }

    private function snapshot(AgentJob $agentJob): Snapshot
    {
        return $agentJob->snapshot ?? throw new RuntimeException("Backup agent job {$agentJob->id} has no snapshot.");
    }

    /**
     * @return array<string, string>
     */
    private static function volumeResultRules(): array
    {
        return [
            'volumes' => 'nullable|array|max:100',
            'volumes.*.volume_id' => 'nullable|string|max:26',
            'volumes.*.status' => sprintf(
                'required_with:volumes|string|in:%s,%s',
                SnapshotFileStatus::Completed->value,
                SnapshotFileStatus::Failed->value,
            ),
            'volumes.*.error' => 'nullable|string|max:10000',
        ];
    }

    /**
     * Persist per-volume upload outcomes onto the snapshot's copy rows.
     *
     * A null $volumeResults means the agent predates multi-volume support:
     * its single reported result applies to the first copy, and any further
     * targets were never uploaded, so they are marked failed.
     *
     * @param  list<array{volume_id?: string|null, status: string, error?: string|null}>|null  $volumeResults
     */
    private function applyVolumeResults(Snapshot $snapshot, ?array $volumeResults): void
    {
        $files = $snapshot->files()->get();

        if ($volumeResults === null) {
            $volumeResults = [['volume_id' => $files->first()?->volume_id, 'status' => SnapshotFileStatus::Completed->value]];

            foreach ($files->skip(1) as $staleFile) {
                $staleFile->markUploadFailed('Agent version does not support multiple volumes; update the agent.');
            }
        }

        foreach ($volumeResults as $volumeResult) {
            $volumeId = $volumeResult['volume_id'] ?? null;

            // Legacy payloads carry no volume id — fall back to the first
            // still-pending copy (pre-migration snapshots have exactly one).
            $file = $files->firstWhere('volume_id', $volumeId)
                ?? ($volumeId === null ? $files->firstWhere('status', SnapshotFileStatus::Pending) : null);

            if ($file === null) {
                continue;
            }

            if ($volumeResult['status'] === SnapshotFileStatus::Completed->value) {
                $file->markUploaded();
            } else {
                $file->markUploadFailed($volumeResult['error'] ?? 'Upload failed');
            }
        }
    }
}
