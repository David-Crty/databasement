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
            // First, check if we have an existing OAuth identity for this provider
            $identity = OAuthIdentity::where('provider', $provider)
                ->where('provider_user_id', $socialiteUser->getId())
                ->first();

            if ($identity) {
                // Update tokens and return existing user
                $this->updateIdentityTokens($identity, $socialiteUser);

                return $identity->user;
            }

            // No existing OAuth identity - try to find user by email
            $user = null;
            $existingUserByEmail = $socialiteUser->getEmail()
                ? User::where('email', $socialiteUser->getEmail())->first()
                : null;

            if (config('oauth.auto_link_by_email') && $existingUserByEmail) {
                $user = $existingUserByEmail;

                // Clear password to enforce OAuth-only login going forward
                $user->password = null;
                $user->save();
            }

            // Create new user if auto-creation is enabled and no existing user found
            if (! $user && config('oauth.auto_create_users')) {
                // Check if email already exists (when auto_link is disabled)
                if ($existingUserByEmail) {
                    throw new \RuntimeException(
                        __('An account with this email already exists. Please log in with your password or contact an administrator.')
                    );
                }
                $user = $this->createUser($socialiteUser);
            }

            if (! $user) {
                throw new \RuntimeException(
                    __('No matching user found and auto-creation is disabled.')
                );
            }

            // Create the OAuth identity for this user
            $this->createIdentity($user, $socialiteUser, $provider);

            return $user;
        });
    }

    /**
     * Create a new user from OAuth data.
     */
    private function createUser(SocialiteUser $socialiteUser): User
    {
        $role = config('oauth.default_role', User::ROLE_MEMBER);

        // Validate the role is valid
        if (! in_array($role, User::ROLES)) {
            $role = User::ROLE_MEMBER;
        }

        $user = User::create([
            'name' => $socialiteUser->getName() ?? $socialiteUser->getNickname() ?? 'OAuth User',
            'email' => $socialiteUser->getEmail(),
            'password' => null, // OAuth users don't need a password
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
