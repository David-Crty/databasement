<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Databasement'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | When running behind a reverse proxy (nginx, Traefik, Kubernetes Ingress),
    | configure trusted proxies so Laravel can correctly determine the client
    | IP address and protocol.
    |
    | Use '*' to trust all proxies, or specify IPs/CIDR
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', TRUSTED_PROXIES_DEFAULT),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | All internal timestamps (database storage, scheduling math, log entries)
    | are kept in UTC regardless of the host's TZ environment variable. This
    | guarantees consistent comparisons between the web app and the worker
    | container, which previously diverged when their system timezones did
    | not match (see GitHub issue #335).
    |
    | The user-facing display timezone is configured separately via
    | "display_timezone" below.
    |
    | See: https://www.php.net/manual/en/timezones.php
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone used when rendering datetimes in the UI, generating
    | timestamps in backup filenames, and elsewhere in user-facing output.
    | The container entrypoint also migrates a legacy TZ value into this
    | variable before PHP boots, so existing deployments keep working.
    | Storage stays UTC.
    |
    */

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Schedule Timezone
    |--------------------------------------------------------------------------
    |
    | Timezone used by Laravel's scheduler to interpret cron expressions —
    | including user-configured BackupSchedule expressions. Mirrors the
    | display timezone so "0 2 * * *" fires at 02:00 local, not 02:00 UTC.
    |
    */

    'schedule_timezone' => env('APP_DISPLAY_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'has_deprecated_aws_env' => (bool) (env('AWS_REGION') || env('AWS_DEFAULT_REGION')),
    'has_deprecated_backup_env' => (bool) env('BACKUP_WORKING_DIRECTORY'),

    'available_locales' => [
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'el' => 'Ελληνικά',
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Repository
    |--------------------------------------------------------------------------
    |
    | The GitHub repository URL for this application. Used for linking to
    | issues, commits, and the repository itself in the footer.
    |
    */

    'github_repo' => 'https://github.com/David-Crty/databasement',

    /*
    |--------------------------------------------------------------------------
    | Git Commit Hash
    |--------------------------------------------------------------------------
    |
    | The git commit hash of the current build. Set via APP_COMMIT_HASH
    | environment variable during deployment, or automatically detected
    | from the local git repository in development.
    |
    */

    'commit_hash' => env('APP_COMMIT_HASH'),

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | The semver version of the current build. Set via APP_VERSION
    | environment variable during deployment (injected at Docker build time
    | from the git tag).
    |
    */

    'version' => env('APP_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the login form is pre-filled with demo credentials.
    | Demo users have read-only access - they can view all features but
    | cannot create, edit, or delete resources.
    |
    */

    'demo_mode' => env('DEMO_MODE', false),
    'demo_user_password' => env('DEMO_USER_PASSWORD', 'demo'),

];
