<?php

namespace App\Traits;

use App\Actions\Backup\TriggerBackupAction;
use App\Enums\BackupDiscoveryState;
use App\Models\Backup;
use App\Models\DatabaseServer;

/**
 * Triggers backup configurations and renders a single toast summarising the
 * outcome. Requires the {@see Toast} trait.
 */
trait RunsServerBackups
{
    protected function triggerAllBackups(DatabaseServer $server): void
    {
        $this->triggerBackups($server->backups);
    }

    /**
     * @param  iterable<Backup>  $backups
     * @param  array<string, list<string>>  $databaseNames  Per backup id, the only databases to back up
     */
    protected function triggerBackups(iterable $backups, array $databaseNames = []): void
    {
        $userId = auth()->id();

        /** @var array<string, BackupDiscoveryState|string> $results Per label: the discovery state once triggered, the error otherwise */
        $results = [];

        foreach ($backups as $backup) {
            try {
                $results[$backup->getDisplayLabel()] = TriggerBackupAction::run(
                    $backup,
                    is_int($userId) ? $userId : null,
                    databaseNames: $databaseNames[$backup->id] ?? null,
                )->discovery;
            } catch (\Throwable $e) {
                $results[$backup->getDisplayLabel()] = $e->getMessage();
            }
        }

        if ($results === []) {
            $this->warning(
                title: __('No backup configurations to start'),
            );

            return;
        }

        $startedCount = count(array_filter($results, fn ($v): bool => $v === BackupDiscoveryState::NotNeeded));
        $failureCount = count(array_filter($results, is_string(...)));
        $waitingCount = count($results) - $startedCount - $failureCount;

        $description = implode("\n", array_map(
            fn (string $label, BackupDiscoveryState|string $status): string => match ($status) {
                BackupDiscoveryState::NotNeeded => "✓ {$label}",
                BackupDiscoveryState::Dispatched, BackupDiscoveryState::AlreadyRunning => "⏳ {$label}",
                default => "✗ {$label}: {$status}",
            },
            array_keys($results),
            array_values($results),
        ));

        if ($failureCount === 0) {
            match (true) {
                in_array(BackupDiscoveryState::Dispatched, $results, true) => $this->success(
                    title: __('Database discovery dispatched to agent. Backups will start once databases are discovered.'),
                    description: $description,
                ),
                in_array(BackupDiscoveryState::AlreadyRunning, $results, true) => $this->info(
                    title: __('Database discovery is already running on the agent. Backups will start once databases are discovered.'),
                    description: $description,
                ),
                default => $this->success(
                    title: __('All backups started successfully!'),
                    description: $description,
                ),
            };

            return;
        }

        if ($failureCount === count($results)) {
            $this->error(
                title: __('All backups failed!'),
                description: $description,
                timeout: 0,
            );

            return;
        }

        $this->warning(
            title: $waitingCount === 0
                ? __(':count backups started, :failures failed', [
                    'count' => $startedCount,
                    'failures' => $failureCount,
                ])
                : __(':count backups started, :waiting waiting for database discovery, :failures failed', [
                    'count' => $startedCount,
                    'waiting' => $waitingCount,
                    'failures' => $failureCount,
                ]),
            description: $description,
        );
    }
}
