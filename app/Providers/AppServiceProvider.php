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
            $config = config('backup.filesystems');

            $provider = new FilesystemProvider($config);

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
        //
    }
}
