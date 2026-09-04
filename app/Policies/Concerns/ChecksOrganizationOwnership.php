<?php

namespace App\Policies\Concerns;

use App\Services\CurrentOrganization;

/**
 * Second line of defence for tenant isolation.
 *
 * Records are normally kept inside the current tenant by the global scopes in
 * {@see \App\Models\Scopes\OrganizationScope} and
 * {@see \App\Models\Scopes\DatabaseServerOrganizationScope}. A policy that only
 * asks "does this user hold the ability" never inspects the record it was handed,
 * so any path that reaches a model with those scopes lifted — a raw
 * `withoutGlobalScopes()` read, or a lookup performed before the organization
 * context is resolved — would authorize it against the caller's own org.
 * Policy methods that receive a model instance check ownership here as well.
 *
 * Applied to the models that carry `organization_id` themselves. Records that
 * inherit their tenant from a related server (snapshots, restores) would need a
 * per-check lookup of that server, so they rely on
 * {@see \App\Models\Scopes\DatabaseServerOrganizationScope} alone.
 */
trait ChecksOrganizationOwnership
{
    /**
     * Whether a record owned by $organizationId belongs to the active tenant.
     *
     * Passes when no organization is resolved: CLI context (artisan commands,
     * queue workers) runs across every org by design, matching the global scopes.
     */
    protected function ownedByCurrentOrganization(?string $organizationId): bool
    {
        $currentOrganization = app(CurrentOrganization::class);

        if (! $currentOrganization->isResolved()) {
            return true;
        }

        return $organizationId !== null
            && $organizationId === $currentOrganization->id();
    }
}
