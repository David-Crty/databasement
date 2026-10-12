<?php

namespace App\Actions\Restore;

use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DTO\RestoreOptions;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

class TriggerRestoreAction
{
    use AsFake, AsObject;

    public function __construct(
        private BackupJobFactory $backupJobFactory,
    ) {}

    /**
     * Create a restore of a snapshot onto a target server and hand it to
     * whatever can reach that server: its agent, or the app's own queue.
     *
     * @throws ValidationException When the snapshot cannot be restored onto this target
     * @throws RuntimeException When no agent job can be built for the restore, which is then failed
     */
    public function handle(
        Snapshot $snapshot,
        DatabaseServer $targetServer,
        string $schemaName,
        RestoreOptions $options = new RestoreOptions,
        ?string $snapshotFileId = null,
        ?int $triggeredByUserId = null,
        ?string $scheduledRestoreId = null,
    ): Restore {
        $restore = $this->backupJobFactory->createRestore(
            snapshot: $snapshot,
            targetServer: $targetServer,
            schemaName: $schemaName,
            triggeredByUserId: $triggeredByUserId,
            options: $options->toArray(),
            scheduledRestoreId: $scheduledRestoreId,
            snapshotFileId: $snapshotFileId,
        );

        DispatchRestoreAction::run($restore);

        return $restore;
    }
}
