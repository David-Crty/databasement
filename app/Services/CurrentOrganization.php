<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;

class CurrentOrganization
{
    public const COOKIE_NAME = 'current_organization_id';

    private ?Organization $organization = null;

    private bool $resolved = false;

    /**
     * Reset the resolved state so the middleware can re-resolve.
     */
    public function reset(): void
    {
        $this->organization = null;
        $this->resolved = false;
    }

    /**
     * Whether an organization context is active.
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Get the current organization's ID.
     */
    public function id(): string
    {
        return $this->organization->id;
    }

    /**
     * Get the current organization model.
     */
    public function model(): Organization
    {
        return $this->organization;
    }

    /**
     * Get the current user's role name in the current org (null for super_admin
     * without membership, or a user with no assigned role).
     */
    public function userRoleName(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        return $user->roleNameIn($this->organization);
    }

    /**
     * Check if user is an admin of the current org (or super_admin).
     */
    public function isOrgAdmin(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->userRoleName() === 'admin';
    }

    /**
     * Switch to a different organization (sets cookie).
     */
    public function switchTo(Organization $org): void
    {
        $this->organization = $org;
        $this->resolved = true;

        Cookie::queue(self::COOKIE_NAME, $org->id, 60 * 24 * 365);
    }

    /**
     * Resolve the organization from the given user context.
     * Called by middleware during request lifecycle.
     */
    public function resolveForUser(User $user, ?string $cookieOrgId = null): void
    {
        // 1. Try cookie org
        if ($cookieOrgId) {
            $org = Organization::find($cookieOrgId);
            if ($org && $this->userCanAccess($user, $org)) {
                $this->set($org);

                return;
            }
        }

        // 2. Fall back to user's first org
        $firstOrg = $user->organizations()->first();
        if ($firstOrg) {
            $this->switchTo($firstOrg);

            return;
        }

        // 3. Super admins with no membership fall back to default org
        if ($user->isSuperAdmin()) {
            $this->switchTo(Organization::default());

            return;
        }
    }

    /**
     * Set the organization directly (without cookie).
     * Used in middleware when cookie already matches.
     */
    public function set(Organization $org): void
    {
        $this->organization = $org;
        $this->resolved = true;
    }

    /**
     * Check if a user can access a given organization.
     */
    private function userCanAccess(User $user, Organization $org): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->organizations()->wherePivot('organization_id', $org->id)->exists();
    }
}
