<?php

namespace App\Actions\Volume;

use App\Models\Volume;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteVolumeAction
{
    use AsFake, AsObject;

    /**
     * Delete a volume with its snapshots, and their files on it unless asked
     * to leave them.
     */
    public function handle(Volume $volume, bool $keepFiles = false): void
    {
        $volume->skipFileCleanup = $keepFiles;
        $volume->delete();
    }
}
