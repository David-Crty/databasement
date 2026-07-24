<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\CurrentOrganization;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the organizations you belong to and which one is currently active. Use the returned ID as the org_id query parameter or X-Organization-Id header to switch organization context. If omitted, the main organization is used by default.')]
#[IsReadOnly]
class ListOrganizationsTool extends Tool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $currentOrgId = app(CurrentOrganization::class)->id();

        $orgs = $user->organizations()->orderByPivot('created_at')->get();

        if ($orgs->isEmpty()) {
            return Response::text('You are not a member of any organization.');
        }

        $lines = $orgs->map(function ($org) use ($currentOrgId, $user) {
            $active = $org->id === $currentOrgId ? ' **(active)**' : '';
            $role = $user->roleNameIn($org) ?? 'unknown';

            return "- **{$org->name}** (ID: {$org->id}){$active}\n  Role: {$role}".($org->is_default ? ' | Default organization' : '');
        });

        return Response::text("Your Organizations ({$orgs->count()}):\n\n".implode("\n\n", $lines->all()));
    }
}
