<?php

namespace App\Livewire\Configuration;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Configuration')]
class Authentication extends Component
{
    /**
     * @return array<int, array{key: string, label: string, class?: string}>
     */
    public function getHeaders(): array
    {
        return [
            ['key' => 'env', 'label' => __('Environment Variable'), 'class' => 'w-56'],
            ['key' => 'value', 'label' => __('Value'), 'class' => 'w-64'],
            ['key' => 'description', 'label' => __('Description')],
        ];
    }

    /**
     * @return array<int, array{value: mixed, env: string, description: string}>
     */
    public function getSsoConfig(): array
    {
        return [
            [
                'env' => 'OAUTH_ONLY_MODE',
                'value' => config('oauth.only_mode') ? 'true' : 'false',
                'description' => __('Hide the password login form and require OAuth/SSO sign-in.'),
            ],
            [
                'env' => 'OAUTH_GOOGLE_ENABLED',
                'value' => config('oauth.providers.google.enabled') ? 'true' : 'false',
                'description' => __('Enable Google OAuth authentication.'),
            ],
            [
                'env' => 'OAUTH_GITHUB_ENABLED',
                'value' => config('oauth.providers.github.enabled') ? 'true' : 'false',
                'description' => __('Enable GitHub OAuth authentication.'),
            ],
            [
                'env' => 'OAUTH_GITLAB_ENABLED',
                'value' => config('oauth.providers.gitlab.enabled') ? 'true' : 'false',
                'description' => __('Enable GitLab OAuth authentication.'),
            ],
            [
                'env' => 'OAUTH_OIDC_ENABLED',
                'value' => config('oauth.providers.oidc.enabled') ? 'true' : 'false',
                'description' => __('Enable generic OIDC authentication (Keycloak, Authentik, etc.).'),
            ],
            [
                'env' => 'OAUTH_AUTO_CREATE_USERS',
                'value' => config('oauth.auto_create_users') ? 'true' : 'false',
                'description' => __('Automatically create users on first OAuth login.'),
            ],
            [
                'env' => 'OAUTH_DEFAULT_ROLE',
                'value' => config('oauth.default_role') ?: '-',
                'description' => __('Default role for new OAuth users: viewer, member, or admin.'),
            ],
            [
                'env' => 'OAUTH_DEFAULT_ORGANIZATION_ID',
                'value' => config('oauth.default_organization_id') ?: '-',
                'description' => __('Organization ID for auto-created OAuth users (defaults to main org).'),
            ],
            [
                'env' => 'OAUTH_AUTO_LINK_BY_EMAIL',
                'value' => config('oauth.auto_link_by_email') ? 'true' : 'false',
                'description' => __('Link OAuth logins to existing users with matching email.'),
            ],
        ];
    }

    public function render(): View
    {
        return view('livewire.configuration.authentication', [
            'headers' => $this->getHeaders(),
            'ssoConfig' => $this->getSsoConfig(),
        ]);
    }
}
