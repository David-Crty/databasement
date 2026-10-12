<?php

namespace App\Services\Agent\Handlers;

use App\Actions\Backup\CompleteBackupAction;
use App\Actions\Backup\FailBackupAction;
use App\Actions\Backup\RecordBackupResultAction;
use App\Enums\SnapshotFileStatus;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Rules\SafePath;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\VolumeTransferResult;
use App\Support\QueueTimeouts;
use RuntimeException;
use Throwable;

class BackupJobHandler implements AgentJobHandler
{
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

        // An empty volumes array carries no outcomes — treat it like a legacy
        // (null) payload rather than a silent success, mirroring fail().
        $backupResult = new BackupResult(
            filename: $result['filename'],
            fileSize: $result['file_size'],
            checksum: $result['checksum'] ?? null,
            volumeResults: $this->volumeResults($snapshot, ! empty($result['volumes']) ? $result['volumes'] : null),
        );

        RecordBackupResultAction::run($snapshot, $backupResult);

        $agentJob->markCompleted();

        // Whole-job-fails rule: an ack that leaves failed copies (or targets a
        // legacy agent couldn't upload to) fails the backup job even though
        // the agent finished its part.
        if ($snapshot->files()->where('status', SnapshotFileStatus::Failed)->exists()) {
            $exception = new RuntimeException(
                'Some volume uploads failed. If this backup targets multiple volumes, make sure the agent is up to date — older agents only upload to the first volume.',
            );
            $snapshot->job->log("Backup failed: {$exception->getMessage()}", 'error');

            FailBackupAction::run($snapshot, $exception);
        } else {
            CompleteBackupAction::run($snapshot, $backupResult);
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
            RecordBackupResultAction::run($snapshot, new BackupResult(
                filename: $result['filename'] ?? '',
                fileSize: $result['file_size'] ?? 0,
                checksum: null,
                volumeResults: $this->volumeResults($snapshot, $result['volumes']),
            ));
        }

        FailBackupAction::run($snapshot, $exception);
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
            'volumes.*.volume_name' => 'nullable|string|max:255',
            'volumes.*.error' => 'nullable|string|max:10000',
            'volumes.*.storage_warning' => 'nullable|string|max:10000',
        ];
    }

    /**
     * Turn the reported per-volume outcomes into results keyed by volume.
     *
     * A null $volumeResults means the agent predates multi-volume support:
     * its single reported result applies to the first copy, and any further
     * targets were never uploaded, so they are marked failed here. Legacy
     * payloads carry no volume id either; such an outcome applies to the
     * first copy still pending (pre-migration snapshots have exactly one).
     *
     * @param  list<array{volume_id?: string|null, volume_name?: string|null, status: string, error?: string|null, storage_warning?: string|null}>|null  $volumeResults
     * @return list<VolumeTransferResult>
     */
    private function volumeResults(Snapshot $snapshot, ?array $volumeResults): array
    {
        $files = $snapshot->files()->with('volume')->get();

        if ($volumeResults === null) {
            $volumeResults = [['volume_id' => $files->first()?->volume_id, 'status' => SnapshotFileStatus::Completed->value]];

            foreach ($files->skip(1) as $staleFile) {
                $staleFile->markUploadFailed('Agent version does not support multiple volumes; update the agent.');
            }
        }

        $pending = $files->where('status', SnapshotFileStatus::Pending)->keyBy('id');
        $results = [];

        foreach ($volumeResults as $volumeResult) {
            $file = ($volumeResult['volume_id'] ?? null) !== null
                ? $files->firstWhere('volume_id', $volumeResult['volume_id'])
                : $pending->first();

            if ($file === null) {
                continue;
            }

            $pending->forget($file->id);

            $failed = $volumeResult['status'] !== SnapshotFileStatus::Completed->value;

            $results[] = new VolumeTransferResult(
                volumeId: $file->volume_id,
                volumeName: $volumeResult['volume_name'] ?? $file->volume->name,
                status: $failed ? SnapshotFileStatus::Failed : SnapshotFileStatus::Completed,
                error: $failed ? ($volumeResult['error'] ?? 'Upload failed') : null,
                storageWarning: $volumeResult['storage_warning'] ?? null,
            );
        }

        return $results;
    }
}
