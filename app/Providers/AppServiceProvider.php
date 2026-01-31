<?php

namespace App\Providers;

use App\Services\Backup\CompressorFactory;
use App\Services\Backup\CompressorInterface;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\Filesystems\FtpFilesystem;
use App\Services\Backup\Filesystems\LocalFilesystem;
use App\Services\Backup\Filesystems\SftpFilesystem;
use App\Services\Backup\ShellProcessor;
use App\Services\DatabaseConnectionTester;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerOAuthServicesConfig();

        // Register database connection tester
        $this->app->singleton(DatabaseConnectionTester::class);

        // Register backup service components
        $this->app->singleton(ShellProcessor::class);
        $this->app->singleton(CompressorFactory::class);
        $this->app->singleton(CompressorInterface::class, function ($app) {
            return $app->make(CompressorFactory::class)->make();
        });
        $this->app->singleton(MysqlDatabase::class);
        $this->app->singleton(PostgresqlDatabase::class);

        // Register FilesystemProvider with configuration
        $this->app->singleton(FilesystemProvider::class, function ($app) {
            $provider = new FilesystemProvider([]);

            // Register filesystem implementations
            $provider->add(new LocalFilesystem);
            $provider->add(new Awss3Filesystem);
            $provider->add(new SftpFilesystem);
            $provider->add(new FtpFilesystem);

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
        $this->ensureBackupTmpFolderExists();
        $this->registerOidcSocialiteProvider();
        $this->validateOAuthConfiguration();

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });
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

    private function ensureBackupTmpFolderExists(): void
    {
        $backupTmpFolder = config('backup.working_directory');

        if ($backupTmpFolder && ! is_dir($backupTmpFolder)) {
            mkdir($backupTmpFolder, 0755, true);
        }
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
        $validRoles = ['viewer', 'member', 'admin'];
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
    }
}
