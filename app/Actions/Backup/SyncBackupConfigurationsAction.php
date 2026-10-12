<?php

namespace App\Actions\Backup;

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Support\BackupConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Sync a collection of backup configurations to a server: upserts rows
 * submitted in the payload and deletes any existing rows missing from it.
 */
class SyncBackupConfigurationsAction
{
    use AsFake, AsObject;

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function handle(DatabaseServer $server, array $entries): void
    {
        if (! $server->backups_enabled) {
            return;
        }

        $serverType = $server->database_type;
        $existing = $server->backups()->get()->keyBy('id');

        // Pre-validate all submitted IDs before any writes
        foreach ($entries as $index => $entry) {
            $existingId = $entry['id'] ?? null;

            if ($existingId !== null && ! $existing->has($existingId)) {
                throw ValidationException::withMessages([
                    "backups.{$index}.id" => "Backup configuration [{$existingId}] does not belong to this database server.",
                ]);
            }
        }

        DB::transaction(function () use ($entries, $serverType, $existing, $server) {
            $submittedIds = [];

            foreach ($entries as $entry) {
                BackupConfiguration::normalizeSelection($entry, $serverType);
                $data = BackupConfiguration::toPersistedData($entry);
                $data['database_server_id'] = $server->id;

                $existingId = $entry['id'] ?? null;
                $volumeIds = array_values(array_filter((array) ($entry['volume_ids'] ?? [])));

                /** @var array<string, mixed> $data */
                if ($existingId !== null) {
                    $backup = $existing->get($existingId);
                    $backup->update($data);
                    $submittedIds[] = $existingId;
                } else {
                    $backup = Backup::create($data);
                    $submittedIds[] = $backup->id;
                }

                $backup->volumes()->sync($volumeIds);
            }

            $toDelete = array_diff($existing->keys()->all(), $submittedIds);

            if ($toDelete !== []) {
                Backup::whereIn('id', $toDelete)->delete();
            }
        });
    }
}
