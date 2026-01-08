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
            'icon' => 'o-key',
            'label' => env('OAUTH_OIDC_LABEL', 'SSO'),
        ],
    ],
];
