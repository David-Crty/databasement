<?php

namespace App\Exceptions\Backup;

use App\Models\Snapshot;
use Exception;

/**
 * Some copies of a snapshot's archive could not be removed from their volume,
 * so the snapshot was kept to keep tracking them.
 */
class SnapshotFilesNotDeletedException extends Exception
{
    /**
     * @param  array<string, string>  $errors  Error per volume name
     */
    public function __construct(public readonly Snapshot $snapshot, public readonly array $errors)
    {
        $details = implode('; ', array_map(
            fn (string $volume, string $error): string => "{$volume}: {$error}",
            array_keys($errors),
            $errors,
        ));

        parent::__construct("Snapshot {$snapshot->id} was kept because its file could not be deleted from every volume ({$details}).");
    }
}
