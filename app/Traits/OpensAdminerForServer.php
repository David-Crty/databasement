<?php

namespace App\Traits;

use App\Models\DatabaseServer;

/**
 * Authorizes the Adminer gate, stores the target server in the session, and
 * dispatches the {@code open-adminer-modal} event. Requires
 * {@see \Illuminate\Foundation\Auth\Access\AuthorizesRequests}.
 */
trait OpensAdminerForServer
{
    protected function openAdminerForServer(DatabaseServer $server): void
    {
        $this->authorize('adminer', DatabaseServer::class);
        abort_unless($server->supportsAdminer(), 403);

        session()->put('adminer_server_id', $server->id);

        $this->dispatch('open-adminer-modal',
            serverName: $server->name,
            databaseIcon: $server->database_type->icon(),
            databaseType: $server->database_type->label(),
            adminerUrl: route('adminer'),
        );
    }
}
