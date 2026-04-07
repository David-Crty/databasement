<?php

namespace App\Services;

use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class OAuthService
{
    /**
     * Find or create a user from OAuth data.
     */
    public function findOrCreateUser(SocialiteUser $socialiteUser, string $provider): User
    {
        return DB::transaction(function () use ($socialiteUser, $provider) {
            $identity = OAuthIdentity::where('provider', $provider)
                ->where('provider_user_id', $socialiteUser->getId())
                ->first();

            if ($identity) {
                $this->updateIdentityTokens($identity, $socialiteUser);
                $this->syncUserRole($identity->user, $socialiteUser, $provider);

                return $identity->user;
            }

            // Resolve role early to fail fast in strict mode before creating anything
            $resolvedRole = $this->resolveOidcRole($socialiteUser, $provider);

            $user = $this->findOrCreateLocalUser($socialiteUser, $provider, $resolvedRole);

            $this->createIdentity($user, $socialiteUser, $provider);

            return $user;
        });
    }

    /**
     * Find an existing local user to link or create a new one.
     *
     * @throws \RuntimeException when no user can be found or created
     */
    private function findOrCreateLocalUser(SocialiteUser $socialiteUser, string $provider, ?string $resolvedRole): User
    {
        $existingUser = $socialiteUser->getEmail()
            ? User::where('email', $socialiteUser->getEmail())->first()
            : null;

        if (config('oauth.auto_link_by_email') && $existingUser) {
            $existingUser->password = null;
            $existingUser->save();

            $this->syncUserRole($existingUser, $socialiteUser, $provider, $resolvedRole);

            return $existingUser;
        }

        if (! config('oauth.auto_create_users')) {
            throw new \RuntimeException(
                __('No matching user found and auto-creation is disabled.')
            );
        }

        if ($existingUser) {
            throw new \RuntimeException(
                __('An account with this email already exists. Please log in with your password or contact an administrator.')
            );
        }

        return $this->createUser($socialiteUser, $resolvedRole);
    }

    /**
     * Resolve user role from OIDC group claims.
     *
     * Returns the mapped role if a match is found, null if mapping is
     * inactive or no match, or throws if strict mode denies access.
     */
    private function resolveOidcRole(SocialiteUser $socialiteUser, string $provider): ?string
    {
        if ($provider !== 'oidc') {
            return null;
        }

        if (! $this->isRoleMappingConfigured()) {
            return null;
        }

        $claim = config('oauth.role_mapping.claim', 'groups');
        $raw = method_exists($socialiteUser, 'getRaw') ? $socialiteUser->getRaw() : [];
        $claimValue = $raw[$claim] ?? null;

        if ($claimValue === null) {
            return $this->handleUnmappedUser();
        }

        // Normalize to array and lowercase for case-insensitive matching
        $userGroups = array_map('mb_strtolower', (array) $claimValue);

        // Check roles in priority order: admin > member > viewer
        foreach (['admin', 'member', 'viewer'] as $role) {
            $configuredGroups = $this->parseGroupList(config("oauth.role_mapping.{$role}", ''));

            if ($configuredGroups !== [] && array_intersect($userGroups, $configuredGroups) !== []) {
                return $role;
            }
        }

        return $this->handleUnmappedUser();
    }

    /**
     * Handle the case where no OIDC group matches any configured mapping.
     *
     * @throws \RuntimeException when strict mode is enabled
     */
    private function handleUnmappedUser(): null
    {
        if (config('oauth.role_mapping.strict')) {
            throw new \RuntimeException(
                __('Your account is not authorized to access this application.')
            );
        }

        return null;
    }

    /**
     * Sync user role from OIDC claims.
     */
    private function syncUserRole(User $user, SocialiteUser $socialiteUser, string $provider, ?string $resolvedRole = null): void
    {
        $role = $resolvedRole ?? $this->resolveOidcRole($socialiteUser, $provider);

        if ($role !== null) {
            $user->role = $role;
            $user->save();
        }
    }

    /**
     * Check if any role mapping env vars are configured.
     */
    private function isRoleMappingConfigured(): bool
    {
        return trim((string) config('oauth.role_mapping.admin', '')) !== ''
            || trim((string) config('oauth.role_mapping.member', '')) !== ''
            || trim((string) config('oauth.role_mapping.viewer', '')) !== '';
    }

    /**
     * Parse a comma-separated group list into a clean, lowercased array.
     *
     * @return array<int, string>
     */
    private function parseGroupList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $group) => mb_strtolower(trim($group)),
            explode(',', $value),
        )));
    }

    /**
     * Create a new user from OAuth data.
     */
    private function createUser(SocialiteUser $socialiteUser, ?string $resolvedRole = null): User
    {
        $role = $resolvedRole ?? config('oauth.default_role', User::ROLE_MEMBER);

        if (! in_array($role, User::ROLES)) {
            $role = User::ROLE_MEMBER;
        }

        $user = new User([
            'name' => $socialiteUser->getName() ?? $socialiteUser->getNickname() ?? 'OAuth User',
            'email' => $socialiteUser->getEmail(),
            'password' => null,
            'role' => $role,
            'invitation_accepted_at' => now(),
        ]);

        // Trust OAuth provider's email verification - set directly to avoid mass assignment
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    /**
     * Create an OAuth identity for a user.
     */
    private function createIdentity(User $user, SocialiteUser $socialiteUser, string $provider): OAuthIdentity
    {
        return OAuthIdentity::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $socialiteUser->getId(),
            'email' => $socialiteUser->getEmail(),
            'name' => $socialiteUser->getName(),
            'avatar' => $socialiteUser->getAvatar(),
            'access_token' => $socialiteUser->token ?? null,
            'refresh_token' => $socialiteUser->refreshToken ?? null,
            'token_expires_at' => isset($socialiteUser->expiresIn)
                ? now()->addSeconds($socialiteUser->expiresIn)
                : null,
        ]);
    }

    /**
     * Update tokens for an existing OAuth identity.
     */
    private function updateIdentityTokens(OAuthIdentity $identity, SocialiteUser $socialiteUser): void
    {
        $identity->update([
            'access_token' => $socialiteUser->token ?? $identity->access_token,
            'refresh_token' => $socialiteUser->refreshToken ?? $identity->refresh_token,
            'token_expires_at' => isset($socialiteUser->expiresIn)
                ? now()->addSeconds($socialiteUser->expiresIn)
                : $identity->token_expires_at,
            'email' => $socialiteUser->getEmail() ?? $identity->email,
            'name' => $socialiteUser->getName() ?? $identity->name,
            'avatar' => $socialiteUser->getAvatar() ?? $identity->avatar,
        ]);
    }

    /**
     * Get enabled OAuth providers for display.
     *
     * @return array<string, array{icon: string, label: string, url: string}>
     */
    public function getEnabledProviders(): array
    {
        $providers = [];

        foreach (config('oauth.providers', []) as $key => $provider) {
            if ($provider['enabled'] ?? false) {
                $providers[$key] = [
                    'icon' => $provider['icon'],
                    'label' => $provider['label'],
                    'url' => route('oauth.redirect', $key),
                ];
            }
        }

        return $providers;
    }
}
