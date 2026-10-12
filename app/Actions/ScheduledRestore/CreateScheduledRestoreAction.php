<?php

namespace App\Actions\ScheduledRestore;

use App\Models\ScheduledRestore;
use App\Services\Backup\DTO\RestoreOptions;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateScheduledRestoreAction
{
    use AsFake, AsObject;

    /**
     * @param  array<string, mixed>  $attributes  Validated columns: name, source_server_id, source_database_name, target_server_id, schema_name, backup_schedule_id, and optionally options and enabled
     *
     * @throws ValidationException When it would restore over the application database
     */
    public function handle(array $attributes): ScheduledRestore
    {
        $scheduledRestore = new ScheduledRestore([
            ...$attributes,
            'options' => RestoreOptions::fromArray((array) ($attributes['options'] ?? []))->toArray() ?: null,
        ]);

        if ($scheduledRestore->targetsAppDatabase()) {
            throw ValidationException::withMessages(['schema_name' => 'Cannot restore over the application database.']);
        }

        $scheduledRestore->save();

        return $scheduledRestore;
    }
}
