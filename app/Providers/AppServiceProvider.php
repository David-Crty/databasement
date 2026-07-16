<?php

namespace App\Providers;

use App\Enums\Ability;
use App\Models\ScheduledRestore;
use App\Models\User;
use App\Policies\RestorePolicy;
use App\Policies\RolePolicy;
use App\Services\AppConfigService;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\CompressorInterface;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use App\Services\Backup\Filesystems\AzureFilesystem;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\Filesystems\FtpFilesystem;
use App\Services\Backup\Filesystems\LocalFilesystem;
use App\Services\Backup\Filesystems\SftpFilesystem;
use App\Services\Backup\ShellProcessor;
use App\Services\CurrentOrganization;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerOAuthServicesConfig();

        $this->app->singleton(CurrentOrganization::class);
        $this->app->singleton(AppConfigService::class);
        $this->app->singleton(ShellProcessor::class);
        $this->app->singleton(CompressorFactory::class);
        $this->app->singleton(CompressorInterface::class, function ($app) {
            return $app->make(CompressorFactory::class)->make();
        });
        // Register FilesystemProvider with configuration
        $this->app->singleton(FilesystemProvider::class, function ($app) {
            $provider = new FilesystemProvider([]);

            // Register filesystem implementations
            $provider->add(new LocalFilesystem);
            $provider->add(new Awss3Filesystem);
            $provider->add(new SftpFilesystem);
            $provider->add(new FtpFilesystem);
            $provider->add(new AzureFilesystem);

            return $provider;
        });
    }

    /**
     * Register OAuth provider configurations for Laravel Socialite.
     *
     * This maps config/oauth.php providers to config/services.php format
     * that Socialite expects, eliminating duplication.
     */
    private function registerOAuthServicesConfig(): void
    {
        $providers = config('oauth.providers', []);

        foreach ($providers as $name => $provider) {
            $serviceConfig = [
                'client_id' => $provider['client_id'] ?? null,
                'client_secret' => $provider['client_secret'] ?? null,
                'redirect' => "/oauth/{$name}/callback",
            ];

            // Add provider-specific config
            if ($name === 'gitlab' && isset($provider['host'])) {
                $serviceConfig['host'] = $provider['host'];
            }

            if ($name === 'oidc' && isset($provider['base_url'])) {
                $serviceConfig['base_url'] = $provider['base_url'];
            }

            config(["services.{$name}" => $serviceConfig]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->warnDeprecatedEnvVars();
        $this->registerOidcSocialiteProvider();
        $this->validateOAuthConfiguration();
        $this->registerBouncer();

        // Mary UI 2.9's <x-tab> references <x-mary-badge> internally, but its
        // service provider only registers the `mary-` internal alias for a fixed
        // subset of components (button/icon/modal/…) and omits badge. Because
        // Blade resolves component tags at compile time, that unregistered alias
        // breaks compilation of every <x-tab> when no component prefix is set (as
        // here). Register the missing alias ourselves. Remove once Mary ships it.
        Blade::component('mary-badge', \Mary\View\Components\Badge::class);

        Gate::policy(ScheduledRestore::class, RestorePolicy::class);
        Gate::policy(\Silber\Bouncer\Database\Role::class, RolePolicy::class);

        Scramble::configure()
            ->routes(fn (Route $route) => Str::startsWith($route->uri, 'api/') && ! Str::startsWith($route->uri, 'api/v1/agent'))
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );

                $orgParam = Parameter::make('org_id', 'query')
                    ->description('Organization ID. If omitted, the main organization is used.')
                    ->setSchema(Schema::fromType(new StringType));

                foreach ($openApi->paths as $path) {
                    foreach ($path->operations as $operation) {
                        $operation->addParameters([$orgParam]);
                    }
                }
            });
    }

    /**
     * Wire Bouncer into the application.
     *
     * Role and ability definitions are global (runtime-editable under
     * Configuration → Roles); only role assignments are scoped per organization,
     * so a user can be an Admin in one org and a Viewer in another. The
     * per-request scope is set by the ScopeBouncer middleware; the global
     * built-in roles (and their abilities) are seeded by migration.
     */
    private function registerBouncer(): void
    {
        // Agent mode runs a single CLI command with no database and never
        // authorizes UI requests.
        if (config('agent.enabled')) {
            return;
        }

        Bouncer::cache();

        // Super admins bypass the catalogue abilities. A blanket Gate::before
        // returning true for everything can't be used: UserPolicy has guards
        // that must also constrain super admins (no self-delete, last super
        // admin). So this only short-circuits the catalogue abilities; the
        // policy abilities (create/update/delete/...) keep their own super-admin
        // handling. A Gate::before that returns null defers to Bouncer's grant
        // resolution — unlike a Gate::define, which would shadow it.
        Gate::before(function (?User $user, string $ability): ?bool {
            if ($user?->isSuperAdmin() && in_array($ability, Ability::names(), true)) {
                return true;
            }

            return null;
        });
    }

    /**
     * Log deprecation warnings for environment variables that have been
     * replaced by in-app configuration (volumes, backup, notifications).
     */
    private function warnDeprecatedEnvVars(): void
    {
        if (config('app.has_deprecated_aws_env')) {
            Log::warning('Deprecated AWS_* environment variables detected. S3 credentials are now configured per-volume in the UI. You can safely remove AWS_* variables from your environment.');
        }

        if (config('app.has_deprecated_backup_env')) {
            Log::warning('Deprecated BACKUP_* environment variables detected. Backup settings are now configured in the UI. You can safely remove BACKUP_* variables from your environment.');
        }

    }

    /**
     * Register the generic OIDC Socialite provider.
     */
    private function registerOidcSocialiteProvider(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('oidc', \SocialiteProviders\OIDC\Provider::class);
        });
    }

    /**
     * Validate OAuth configuration at boot time for faster feedback.
     * Skips validation in console to avoid breaking artisan commands.
     */
    private function validateOAuthConfiguration(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $this->performOAuthValidation();
    }

    /**
     * Perform OAuth configuration validation.
     *
     * @internal Exposed as public for testing purposes
     */
    public function performOAuthValidation(): void
    {
        // OIDC role mapping only ever targets the built-in roles (the env keys
        // are OAUTH_OIDC_ROLE_MAP_ADMIN/MEMBER/OPERATOR/VIEWER), so validate
        // against those names without a per-request roles query.
        $validRoles = ['admin', 'member', 'operator', 'viewer'];
        $defaultRole = config('oauth.default_role');

        if ($defaultRole && ! in_array($defaultRole, $validRoles)) {
            throw new \InvalidArgumentException(
                "Invalid OAUTH_DEFAULT_ROLE '{$defaultRole}'. Must be one of: ".implode(', ', $validRoles)
            );
        }

        $providers = config('oauth.providers', []);

        foreach ($providers as $name => $providerConfig) {
            if (! ($providerConfig['enabled'] ?? false)) {
                continue;
            }

            if (empty($providerConfig['client_id']) || empty($providerConfig['client_secret'])) {
                throw new \InvalidArgumentException(
                    "OAuth provider '{$name}' is enabled but missing client_id or client_secret"
                );
            }

            if ($name === 'oidc' && empty($providerConfig['base_url'])) {
                throw new \InvalidArgumentException(
                    "OAuth provider 'oidc' is enabled but missing required base URL"
                );
            }
        }

        // Validate role mapping: strict mode requires at least one mapping (only when OIDC is enabled)
        if (config('oauth.providers.oidc.enabled', false)) {
            $roleMapping = config('oauth.role_mapping', []);
            $hasMapping = false;
            foreach ($validRoles as $role) {
                if (trim((string) ($roleMapping[$role] ?? '')) !== '') {
                    $hasMapping = true;
                    break;
                }
            }

            if (! empty($roleMapping['strict']) && ! $hasMapping) {
                throw new \InvalidArgumentException(
                    'OAUTH_OIDC_ROLE_STRICT is enabled but no role mappings are configured. Set at least one of: OAUTH_OIDC_ROLE_MAP_ADMIN, OAUTH_OIDC_ROLE_MAP_MEMBER, OAUTH_OIDC_ROLE_MAP_OPERATOR, OAUTH_OIDC_ROLE_MAP_VIEWER'
                );
            }
        }
    }
}
