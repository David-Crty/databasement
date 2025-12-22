<?php

namespace App\Providers;

use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\Filesystems\LocalFilesystem;
use App\Services\Backup\GzipCompressor;
use App\Services\Backup\ShellProcessor;
use App\Services\DatabaseConnectionTester;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register database connection tester
        $this->app->singleton(DatabaseConnectionTester::class);

        // Register backup service components
        $this->app->singleton(ShellProcessor::class);
        $this->app->singleton(GzipCompressor::class);
        $this->app->singleton(MysqlDatabase::class);
        $this->app->singleton(PostgresqlDatabase::class);

        // Register FilesystemProvider with configuration
        $this->app->singleton(FilesystemProvider::class, function ($app) {
            $provider = new FilesystemProvider([]);

            // Register filesystem implementations
            $provider->add(new LocalFilesystem);
            $provider->add(new Awss3Filesystem);

            return $provider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });
    }
}
