<?php

namespace App\Services\Backup;

use App\Livewire\Forms\BackupForm;
use App\Models\Backup;
use App\Models\DatabaseServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sync a collection of backup configurations to a server: upserts rows
 * submitted in the payload and deletes any existing rows missing from it.
 *
 * Shared between the Livewire form (`DatabaseServerForm::update/store`)
 * and the REST API controller (`DatabaseServerController::store/update`)
 * so both entry points stay in lock-step.
 */
class SyncBackupConfigurationsAction
{
    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function execute(DatabaseServer $server, array $entries): void
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
                BackupForm::normalizeSelection($entry, $serverType);
                $data = BackupForm::toPersistedData($entry);
                $data['database_server_id'] = $server->id;

                $existingId = $entry['id'] ?? null;

                if ($existingId !== null) {
                    $existing->get($existingId)->update($data);
                    $submittedIds[] = $existingId;
                } else {
                    $submittedIds[] = Backup::create($data)->id;
                }
            }

            $toDelete = array_diff($existing->keys()->all(), $submittedIds);

            if ($toDelete !== []) {
                Backup::whereIn('id', $toDelete)->delete();
            }
        });
    }
}
