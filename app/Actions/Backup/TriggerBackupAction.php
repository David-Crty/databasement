<?php

namespace App\Actions\Backup;

use App\Enums\BackupDiscoveryState;
use App\Models\Backup;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DTO\TriggerBackupResult;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class TriggerBackupAction
{
    use AsFake, AsObject;

    public function __construct(
        private BackupJobFactory $backupJobFactory,
    ) {}

    /**
     * Trigger one backup configuration.
     *
     * Agent-backed servers in all/pattern mode get no snapshots yet: the agent
     * lists the databases first, through a discovery job. Otherwise a trigger
     * that leaves no database to back up is refused: the listing failed or
     * matched nothing, which a failed backup job already records.
     *
     * @param  'manual'|'scheduled'  $method
     *
     * @throws ValidationException When the server's backups are disabled, or a database cannot be backed up
     */
    public function handle(Backup $backup, ?int $triggeredByUserId = null, string $method = 'manual'): TriggerBackupResult
    {
        $server = $backup->databaseServer;

        if (! $server->backups_enabled) {
            throw ValidationException::withMessages(['backup' => 'Backups are disabled for this database server.']);
        }

        $snapshots = $this->backupJobFactory->createSnapshots(
            backup: $backup,
            method: $method,
            triggeredByUserId: $triggeredByUserId,
        );

        if (empty($snapshots) && $server->agent_id) {
            $dispatched = DispatchDiscoveryAction::run($backup, $method, $triggeredByUserId);

            return new TriggerBackupResult([], $dispatched ? BackupDiscoveryState::Dispatched : BackupDiscoveryState::AlreadyRunning);
        }

        if (empty($snapshots)) {
            throw ValidationException::withMessages(['backup' => 'No backup was started: the databases could not be listed, or none matched.']);
        }

        foreach ($snapshots as $snapshot) {
            DispatchBackupAction::run($snapshot);
        }

        return new TriggerBackupResult($snapshots, BackupDiscoveryState::NotNeeded);
    }
}
