<?php

namespace App\Actions\ScheduledRestore;

use App\Models\ScheduledRestore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteScheduledRestoreAction
{
    use AsFake, AsObject;

    public function handle(ScheduledRestore $scheduledRestore): void
    {
        $scheduledRestore->delete();
    }
}
