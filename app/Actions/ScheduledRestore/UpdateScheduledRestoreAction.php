<?php

namespace App\Actions\ScheduledRestore;

use App\Models\ScheduledRestore;
use App\Services\Backup\DTO\RestoreOptions;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateScheduledRestoreAction
{
    use AsFake, AsObject;

    /**
     * @param  array<string, mixed>  $attributes  Validated columns to change (see CreateScheduledRestoreAction); a missing key is left unchanged
     *
     * @throws ValidationException When it would restore over the application database
     */
    public function handle(ScheduledRestore $scheduledRestore, array $attributes): ScheduledRestore
    {
        if (array_key_exists('options', $attributes)) {
            $attributes['options'] = RestoreOptions::fromArray((array) ($attributes['options'] ?? []))->toArray() ?: null;
        }

        $scheduledRestore->fill($attributes)->unsetRelation('targetServer');

        if ($scheduledRestore->targetsAppDatabase()) {
            throw ValidationException::withMessages(['schema_name' => 'Cannot restore over the application database.']);
        }

        $scheduledRestore->save();

        return $scheduledRestore;
    }
}
