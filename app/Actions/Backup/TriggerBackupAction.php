<?php

namespace App\Actions\Backup;

use App\Enums\BackupDiscoveryState;
use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
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
     * Given $databaseNames, only those databases are backed up, with no
     * listing or discovery. Each must fall within the configuration's scope.
     *
     * @param  'manual'|'scheduled'  $method
     * @param  list<string>|null  $databaseNames
     *
     * @throws ValidationException When the server's backups are disabled, or a database cannot be backed up
     */
    public function handle(Backup $backup, ?int $triggeredByUserId = null, string $method = 'manual', ?array $databaseNames = null): TriggerBackupResult
    {
        $server = $backup->databaseServer;

        if (! $server->backups_enabled) {
            throw ValidationException::withMessages(['backup' => 'Backups are disabled for this database server.']);
        }

        if ($databaseNames !== null) {
            $this->ensureWithinScope($backup, $databaseNames);
        }

        $snapshots = $databaseNames === null
            ? $this->backupJobFactory->createSnapshots(
                backup: $backup,
                method: $method,
                triggeredByUserId: $triggeredByUserId,
            )
            : array_map(
                fn (string $databaseName): Snapshot => $this->backupJobFactory->createSnapshot($backup, $databaseName, $method, $triggeredByUserId),
                array_values(array_unique($databaseNames)),
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

    /**
     * @param  list<string>  $databaseNames
     *
     * @throws ValidationException
     */
    private function ensureWithinScope(Backup $backup, array $databaseNames): void
    {
        $databaseType = $backup->databaseServer->database_type;

        if ($databaseType === DatabaseType::REDIS) {
            throw ValidationException::withMessages(['databases' => 'A Redis backup always covers the whole instance.']);
        }

        if ($databaseNames === []) {
            throw ValidationException::withMessages(['databases' => 'Select at least one database to back up.']);
        }

        $outOfScope = match (true) {
            $databaseType->identifiesDatabasesByPath() => array_diff($databaseNames, $backup->database_names ?? []),
            default => match ($backup->database_selection_mode) {
                DatabaseSelectionMode::All => [],
                DatabaseSelectionMode::Pattern => array_diff($databaseNames, DatabaseServer::filterDatabasesByPattern($databaseNames, $backup->database_include_pattern ?? '')),
                DatabaseSelectionMode::Selected => array_diff($databaseNames, $backup->database_names ?? []),
            },
        };

        if ($outOfScope !== []) {
            throw ValidationException::withMessages(['databases' => 'Not covered by this backup configuration: '.implode(', ', $outOfScope).'.']);
        }
    }
}
