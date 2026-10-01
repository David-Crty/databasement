<?php

namespace App\Services\Agent\Handlers;

use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DispatchBackupAction;
use Throwable;

class DiscoveryJobHandler implements AgentJobHandler
{
    public function __construct(
        private BackupJobFactory $backupJobFactory,
        private DispatchBackupAction $dispatchBackup,
    ) {}

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

    /**
     * A pattern may match no database, which completes without backups,
     * as it does on the app's own queue.
     */
    public function completionRules(): array
    {
        return [
            'databases' => 'present|array',
            'databases.*' => 'required|string|max:255|distinct',
        ];
    }

    /**
     * Create a snapshot and a backup job for each discovered database.
     */
    public function complete(AgentJob $agentJob, array $result): array
    {
        $payload = $agentJob->payload;
        $backup = $this->backup($agentJob);

        abort_if($backup === null, 422, 'Backup configuration not found for this discovery job.');

        foreach ($result['databases'] as $databaseName) {
            $snapshot = $this->backupJobFactory->createSnapshot(
                $backup,
                $databaseName,
                $payload['method'] ?? 'manual',
                $payload['triggered_by_user_id'] ?? null,
            );

            $this->dispatchBackup->execute($snapshot);
        }

        $agentJob->markCompleted();

        return ['jobs_created' => count($result['databases'])];
    }

    public function failureRules(): array
    {
        return [];
    }

    /**
     * Record a failed snapshot and notify, as the app's own queue does when
     * it cannot list a server's databases.
     */
    public function fail(AgentJob $agentJob, Throwable $exception, array $result): void
    {
        $payload = $agentJob->payload;
        $backup = $this->backup($agentJob);

        if ($backup === null) {
            return;
        }

        $this->backupJobFactory->recordPreflightFailure(
            $backup,
            $payload['method'] ?? 'manual',
            $payload['triggered_by_user_id'] ?? null,
            $exception,
        );
    }

    private function backup(AgentJob $agentJob): ?Backup
    {
        $backupId = $agentJob->payload['backup_id'] ?? null;

        if ($backupId === null) {
            return null;
        }

        return Backup::with(['databaseServer', 'volumes'])
            ->where('id', $backupId)
            ->where('database_server_id', $agentJob->database_server_id)
            ->first();
    }
}
