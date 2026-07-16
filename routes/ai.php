<?php

use App\Http\Middleware\ScopeBouncer;
use App\Http\Middleware\SetCurrentOrganization;
use App\Mcp\Servers\DatabasementServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('databasement', DatabasementServer::class);

// ScopeBouncer must follow SetCurrentOrganization (and auth): it scopes Bouncer to
// the resolved org so a user's org-scoped role abilities resolve when tools call
// $user->can(...). Without it, non-super-admin members are wrongly denied.
Mcp::web('/mcp', DatabasementServer::class)
    ->middleware(['auth:sanctum', SetCurrentOrganization::class, ScopeBouncer::class]);
