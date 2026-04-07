<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OAuth Default Role
    |--------------------------------------------------------------------------
    |
    | The role assigned to new users created via OAuth when no matching
    | email exists in the database. Must be one of: viewer, member, admin
    |
    */
    'default_role' => env('OAUTH_DEFAULT_ROLE', 'member'),

    /*
    |--------------------------------------------------------------------------
    | Auto-link by Email
    |--------------------------------------------------------------------------
    |
    | When enabled, if an OAuth login's email matches an existing user,
    | the OAuth identity will be automatically linked to that user.
    |
    */
    'auto_link_by_email' => env('OAUTH_AUTO_LINK_BY_EMAIL', true),

    /*
    |--------------------------------------------------------------------------
    | Auto-create Users
    |--------------------------------------------------------------------------
    |
    | When enabled, users logging in via OAuth who don't have an existing
    | account will automatically have one created with the default role.
    | This effectively allows OAuth registration without a public register page.
    |
    */
    'auto_create_users' => env('OAUTH_AUTO_CREATE_USERS', true),

    /*
    |--------------------------------------------------------------------------
    | Remember Me
    |--------------------------------------------------------------------------
    |
    | When enabled, OAuth logins will create a long-lived "remember me" session.
    | Set to false for shorter session lifetimes.
    |
    */
    'remember_me' => env('OAUTH_REMEMBER_ME', true),

    /*
    |--------------------------------------------------------------------------
    | OIDC Role Mapping
    |--------------------------------------------------------------------------
    |
    | Map OIDC group claims to Databasement roles. When at least one
    | ROLE_MAP is set, mapping is active. Roles are checked in priority
    | order: admin > member > viewer. The first match wins.
    |
    | When strict mode is enabled, users without a matching group are
    | denied access entirely (even returning users).
    |
    */
    'role_mapping' => [
        'claim' => env('OAUTH_OIDC_ROLE_CLAIM', 'groups'),
        'admin' => env('OAUTH_OIDC_ROLE_MAP_ADMIN', ''),
        'member' => env('OAUTH_OIDC_ROLE_MAP_MEMBER', ''),
        'viewer' => env('OAUTH_OIDC_ROLE_MAP_VIEWER', ''),
        'strict' => env('OAUTH_OIDC_ROLE_STRICT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth Providers
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific OAuth providers. Each provider can be
    | individually enabled by setting its environment variable to true.
    |
    */
    'providers' => [
        'google' => [
            'enabled' => env('OAUTH_GOOGLE_ENABLED', false),
            'client_id' => env('OAUTH_GOOGLE_CLIENT_ID'),
            'client_secret' => env('OAUTH_GOOGLE_CLIENT_SECRET'),
            'icon' => 'fab-google',
            'label' => 'Google',
        ],
        'github' => [
            'enabled' => env('OAUTH_GITHUB_ENABLED', false),
            'client_id' => env('OAUTH_GITHUB_CLIENT_ID'),
            'client_secret' => env('OAUTH_GITHUB_CLIENT_SECRET'),
            'icon' => 'fab-github',
            'label' => 'GitHub',
        ],
        'gitlab' => [
            'enabled' => env('OAUTH_GITLAB_ENABLED', false),
            'client_id' => env('OAUTH_GITLAB_CLIENT_ID'),
            'client_secret' => env('OAUTH_GITLAB_CLIENT_SECRET'),
            'host' => env('OAUTH_GITLAB_HOST', 'https://gitlab.com'),
            'icon' => 'fab-gitlab',
            'label' => 'GitLab',
        ],
        'oidc' => [
            'enabled' => env('OAUTH_OIDC_ENABLED', false),
            'client_id' => env('OAUTH_OIDC_CLIENT_ID'),
            'client_secret' => env('OAUTH_OIDC_CLIENT_SECRET'),
            'base_url' => env('OAUTH_OIDC_BASE_URL'),
            'extra_scopes' => env('OAUTH_OIDC_SCOPES', ''),
            'icon' => 'o-key',
            'label' => env('OAUTH_OIDC_LABEL', 'SSO'),
        ],
    ],
];
