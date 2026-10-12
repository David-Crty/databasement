<?php

namespace App\Actions\DatabaseServer;

use App\Models\DatabaseServer;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteDatabaseServerAction
{
    use AsFake, AsObject;

    /**
     * Delete a server with its snapshots, and their files unless asked to
     * leave them.
     */
    public function handle(DatabaseServer $server, bool $keepFiles = false): void
    {
        $server->skipFileCleanup = $keepFiles;
        $server->delete();
    }
}
