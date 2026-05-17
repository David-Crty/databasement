<?php

namespace App\Traits;

use App\Models\DatabaseServer;
use App\Services\Backup\TriggerBackupAction;

/**
 * Triggers every backup configuration on a server and renders a single toast
 * summarising the outcome. Requires the {@see Toast} trait.
 */
trait RunsServerBackups
{
    protected function triggerAllBackups(DatabaseServer $server, TriggerBackupAction $action): void
    {
        $userId = auth()->id();
        $results = [];

        foreach ($server->backups as $backup) {
            try {
                $action->execute($backup, is_int($userId) ? $userId : null);
                $results[$backup->getDisplayLabel()] = 'success';
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

        $successCount = count(array_filter($results, fn ($v): bool => $v === 'success'));
        $failureCount = count($results) - $successCount;

        $description = implode("\n", array_map(
            fn (string $label, string $status): string => ($status === 'success' ? '✓' : '✗')." {$label}",
            array_keys($results),
            array_values($results),
        ));

        if ($failureCount === 0) {
            $this->success(
                title: __('All backups started successfully!'),
                description: $description,
            );

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
