<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
    public function handle(): int
    {
        $this->info('Waiting for database connection...');

        $maxRetries = 60;
        $retryDelay = 1; // seconds
        $driverName = config('database.default');
        $isSqlite = config("database.connections.{$driverName}.driver") === 'sqlite';

        $connected = false;

        for ($i = 0; $i < $maxRetries; $i++) {
            if ($i > 0) {
                sleep($retryDelay);
            }

            try {
                DB::connection()->getPdo();

                if (! $connected) {
                    $this->info('Database connection established!');
                    $connected = true;
                }

                if ($this->option('check-migrations')) {
                    $this->checkMigrations();
                }

                return 0;
            } catch (\Exception $e) {
                if ($this->option('allow-missing-db') && $this->isMissingDatabaseError($e, $isSqlite)) {
                    $this->info('Database connection works. (Database not created yet).');

                    return 0;
                }

                // Release the connection to avoid holding SQLite file locks between retries
                try {
                    DB::purge();
                } catch (\Exception) {
                    // Ignore purge errors
                }

                $this->warn("Not ready yet. Retrying in {$retryDelay} seconds... ({$i}/{$maxRetries})");
                $this->warn($e->getMessage());
            }
        }

        $this->error('Database not ready after multiple attempts.');

        return 1;
    }

    /**
     * Check if all migrations have been run.
     */
    private function checkMigrations(): void
    {
        $this->info('Checking migrations...');

        if (! DB::connection()->getSchemaBuilder()->hasTable('migrations')) {
            throw new \RuntimeException('Migrations table does not exist.');
        }

        $ranMigrations = DB::table('migrations')->orderBy('batch')->pluck('migration')->all();

        $migrationPath = database_path('migrations');
        $allMigrations = [];
        if (is_dir($migrationPath)) {
            foreach (scandir($migrationPath) as $file) {
                if (str_ends_with($file, '.php')) {
                    $allMigrations[] = str_replace('.php', '', $file);
                }
            }
            sort($allMigrations);
        }

        $pendingMigrations = array_diff($allMigrations, $ranMigrations);

        if (count($pendingMigrations) > 0) {
            throw new \RuntimeException('Pending migrations: '.implode(', ', $pendingMigrations));
        }

        $this->info('All migrations have been run!');
    }

    /**
     * Determine if the exception indicates a missing database (not a connection failure).
     */
    private function isMissingDatabaseError(\Exception $e, bool $isSqlite): bool
    {
        $message = $e->getMessage();

        if ($isSqlite && str_contains($message, 'Database file at path')) {
            return true;
        }

        return str_contains($message, 'Unknown database')
            || (bool) preg_match('/database\s+"[^"]+"\s+does not exist/i', $message);
    }
}
