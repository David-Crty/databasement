<?php

namespace App\Traits;

use App\Facades\AppConfig;
use App\Models\DatabaseServer;

/**
 * Authorizes the Adminer gate, stores the target server in the session, and
 * dispatches the {@code open-adminer-modal} event. When the feature is
 * disabled but the caller is an admin, dispatches a promo modal instead.
 * Requires {@see \Illuminate\Foundation\Auth\Access\AuthorizesRequests}.
 */
trait OpensAdminerForServer
{
    protected function openAdminerForServer(DatabaseServer $server): void
    {
        if (! AppConfig::get('app.adminer_enabled') && auth()->user()?->isAdmin()) {
            $this->dispatch('open-adminer-promo-modal');

            return;
        }

        abort_unless($server->supportsAdminer(), 403);
        $this->authorize('adminer', DatabaseServer::class);

        session()->put('adminer_server_id', $server->id);

        $this->dispatch('open-adminer-modal',
            serverName: $server->name,
            databaseIcon: $server->database_type->icon(),
            databaseType: $server->database_type->label(),
            adminerUrl: route('adminer'),
        );
    }
}
