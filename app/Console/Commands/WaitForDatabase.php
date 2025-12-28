<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WaitForDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:wait
        {--allow-missing-db : Return success if connection works but database is missing}
        {--check-migrations : Also verify that all migrations have been run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wait for the database connection to be established';

    /**
     * Execute the console command.
     */
    public function handle(Migrator $migrator): int
    {
        $this->info('Waiting for database connection...');

        $maxRetries = 60;
        $retryDelay = 1; // seconds

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                DB::connection()->getPdo();
                $this->info('Database connection established!');

                if ($this->option('check-migrations')) {
                    $this->checkMigrations($migrator);
                }

                return 0;
            } catch (\Exception $e) {
                if ($this->option('allow-missing-db')) {
                    // if driver is sqlite, then the database does not exist yet
                    if (DB::connection()->getDriverName() === 'sqlite' && str_contains($e->getMessage(), 'Database file at path')) {
                        $this->info('Sqlite database file not found. (Database not created yet).');

                        return 0;
                    }
                    if (str_contains($e->getMessage(), 'Unknown database')) {
                        $this->info('Database connection established! (Database not created yet).');

                        return 0;
                    }
                }

                $this->warn("Not ready yet. Retrying in {$retryDelay} seconds... ({$i}/{$maxRetries})");
                $this->warn($e->getMessage());

                // Force a fresh connection attempt on the next iteration
                try {
                    DB::purge();
                } catch (\Exception $purgeException) {
                    // Ignore purge errors
                }

                sleep($retryDelay);
            }
        }

        $this->error('Database not ready after multiple attempts.');

        return 1;
    }

    /**
     * @throws RuntimeException
     */
    private function checkMigrations(Migrator $migrator): void
    {
        $this->info('Checking migrations...');

        $migrator->setConnection(DB::getDefaultConnection());
        $migrationPath = database_path('migrations');

        if (! $migrator->repositoryExists()) {
            throw new RuntimeException('Migrations table does not exist.');
        }

        $ranMigrations = $migrator->getRepository()->getRan();
        $allMigrations = array_keys($migrator->getMigrationFiles($migrationPath));
        $pendingMigrations = array_diff($allMigrations, $ranMigrations);

        if (count($pendingMigrations) > 0) {
            throw new RuntimeException('Pending migrations: '.implode(', ', $pendingMigrations));
        }

        $this->info('All migrations have been run!');
    }
}
