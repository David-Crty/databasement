<?php

namespace App\Services\Backup\DTO;

use App\Enums\BackupDiscoveryState;
use App\Models\Snapshot;

readonly class TriggerBackupResult
{
    /**
     * @param  Snapshot[]  $snapshots  Empty while a discovery stands in for them
     */
    public function __construct(
        public array $snapshots,
        public BackupDiscoveryState $discovery,
    ) {}

    /**
     * The outcome in English, for the API, MCP and console.
     */
    public function message(): string
    {
        $count = count($this->snapshots);

        return match ($this->discovery) {
            BackupDiscoveryState::Dispatched => 'Database discovery dispatched to agent. Backups will start once databases are discovered.',
            BackupDiscoveryState::AlreadyRunning => 'Database discovery is already running on the agent. Backups will start once databases are discovered.',
            BackupDiscoveryState::NotNeeded => $count === 1
                ? 'Backup started successfully!'
                : "{$count} database backups started successfully!",
        };
    }
}
