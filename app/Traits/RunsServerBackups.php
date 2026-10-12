<?php

namespace App\Traits;

use App\Actions\Backup\TriggerBackupAction;
use App\Enums\BackupDiscoveryState;
use App\Models\DatabaseServer;

/**
 * Triggers every backup configuration on a server and renders a single toast
 * summarising the outcome. Requires the {@see Toast} trait.
 */
trait RunsServerBackups
{
    protected function triggerAllBackups(DatabaseServer $server): void
    {
        $userId = auth()->id();

        /** @var array<string, BackupDiscoveryState|string> $results Per label: the discovery state once triggered, the error otherwise */
        $results = [];

        foreach ($server->backups as $backup) {
            try {
                $results[$backup->getDisplayLabel()] = TriggerBackupAction::run($backup, is_int($userId) ? $userId : null)->discovery;
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

        $successCount = count(array_filter($results, fn ($v): bool => $v instanceof BackupDiscoveryState));
        $failureCount = count($results) - $successCount;

        $description = implode("\n", array_map(
            fn (string $label, BackupDiscoveryState|string $status): string => match ($status) {
                BackupDiscoveryState::NotNeeded => '✓',
                BackupDiscoveryState::Dispatched, BackupDiscoveryState::AlreadyRunning => '⏳',
                default => '✗',
            }." {$label}",
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

        if ($successCount === 0) {
            $this->error(
                title: __('All backups failed!'),
                description: $description,
                timeout: 0,
            );

            return;
        }

        $this->warning(
            title: __(':count backups started, :failures failed', [
                'count' => $successCount,
                'failures' => $failureCount,
            ]),
            description: $description,
        );
    }
}
