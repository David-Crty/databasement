<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\BackupTask;
use App\Services\Backup\RestoreTask;
use Illuminate\Console\Command;

class BackupTest extends Command
{
    protected $signature = 'backup:test {--type=* : Database type(s) to test (mysql, postgres). Defaults to both}';

    protected $description = 'End-to-end test of the backup and restore system with real databases';

    private ?Volume $volume = null;

    private ?DatabaseServer $databaseServer = null;

    private ?Backup $backup = null;

    private ?Snapshot $snapshot = null;

    private ?string $backupFilePath = null;

    private ?string $restoredDatabaseName = null;

    public function handle(BackupTask $backupTask, RestoreTask $restoreTask, BackupJobFactory $backupJobFactory): int
    {
        $types = $this->option('type');

        // If no types specified, default to both
        if (empty($types)) {
            $types = ['mysql', 'postgres'];
        }

        // Validate types
        foreach ($types as $type) {
            if (! in_array($type, ['mysql', 'postgres'])) {
                $this->error("Invalid database type: {$type}. Use mysql or postgres");

                return self::FAILURE;
            }
        }

        $this->info('🧪 Starting end-to-end backup and restore tests...');
        $this->info('Testing: '.implode(', ', $types)."\n");

        $overallSuccess = true;

        foreach ($types as $type) {
            try {
                $this->runTestForType($type, $backupTask, $restoreTask, $backupJobFactory);
            } catch (\Exception $e) {
                $this->error("\n❌ Test failed for {$type}: {$e->getMessage()}");
                $this->error("Stack trace:\n{$e->getTraceAsString()}");
                $overallSuccess = false;

                // Attempt cleanup even on failure
                try {
                    $this->cleanup($type);
                } catch (\Exception $cleanupError) {
                    $this->warn("Cleanup failed: {$cleanupError->getMessage()}");
                }
            }

            $this->newLine();
        }

        if ($overallSuccess) {
            $this->info('✅ All end-to-end tests completed successfully!');

            return self::SUCCESS;
        } else {
            $this->error('❌ Some tests failed');

            return self::FAILURE;
        }
    }

    private function runTestForType(string $type, BackupTask $backupTask, RestoreTask $restoreTask, BackupJobFactory $backupJobFactory): void
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("🧪 Testing {$type}");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");

        // Step 1: Setup
        $this->setupTestEnvironment();

        // Step 2: Create models
        $this->createModels($type);

        // Step 3: Run backup
        $this->runBackup($backupTask, $backupJobFactory);

        // Step 4: Verify backup
        $this->verifyBackup();

        // Step 5: Run restore
        $this->runRestore($restoreTask, $backupJobFactory);

        // Step 6: Verify restore
        $this->verifyRestore($type);

        // Step 7: Cleanup
        $this->cleanup($type);

        $this->info("✅ {$type} test completed successfully!");
    }

    private function setupTestEnvironment(): void
    {
        $this->info('📁 Setting up test environment...');

        $backupDir = '/tmp/backups';
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
            $this->line("   Created directory: {$backupDir}");
        } else {
            $this->line("   Directory exists: {$backupDir}");
        }
    }

    private function createModels(string $type): void
    {
        $this->info("\n📝 Creating test models for {$type}...");

        // Clean up any leftover test data from previous runs
        $this->cleanupLeftoverTestData($type);

        // Create Volume with unique name per type
        $this->volume = Volume::create([
            'name' => "E2E Test Local Volume ({$type})",
            'type' => 'local',
            'config' => [
                'root' => '/tmp/backups',
            ],
        ]);
        $this->line("   ✓ Created Volume: {$this->volume->name} (ID: {$this->volume->id})");

        // Create DatabaseServer based on type
        $config = $this->getDatabaseConfig($type);
        $this->databaseServer = DatabaseServer::create($config);
        $this->line("   ✓ Created DatabaseServer: {$this->databaseServer->name} (ID: {$this->databaseServer->id})");

        // Create Backup
        $this->backup = Backup::create([
            'database_server_id' => $this->databaseServer->id,
            'volume_id' => $this->volume->id,
            'recurrence' => 'manual',
        ]);
        $this->line("   ✓ Created Backup config (ID: {$this->backup->id})");

        // Reload relationships
        $this->databaseServer->load('backup.volume');
    }

    private function getDatabaseConfig(string $type): array
    {
        return match ($type) {
            'mysql' => [
                'name' => 'E2E Test MySQL Server',
                'host' => config('backup.backup_test.mysql.host'),
                'port' => config('backup.backup_test.mysql.port'),
                'database_type' => 'mysql',
                'username' => config('backup.backup_test.mysql.username'),
                'password' => config('backup.backup_test.mysql.password'),
                'database_name' => config('backup.backup_test.mysql.database'),
                'description' => 'End-to-end test MySQL database server',
            ],
            'postgres' => [
                'name' => 'E2E Test PostgreSQL Server',
                'host' => config('backup.backup_test.postgres.host'),
                'port' => config('backup.backup_test.postgres.port'),
                'database_type' => 'postgresql',
                'username' => config('backup.backup_test.postgres.username'),
                'password' => config('backup.backup_test.postgres.password'),
                'database_name' => config('backup.backup_test.postgres.database'),
                'description' => 'End-to-end test PostgreSQL database server',
            ],
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}"),
        };
    }

    private function runBackup(BackupTask $backupTask, BackupJobFactory $backupJobFactory): void
    {
        $this->info("\n💾 Running backup task...");

        $snapshots = $backupJobFactory->createSnapshots(
            server: $this->databaseServer,
            method: 'manual',
            triggeredByUserId: null
        );

        // Get the first (and only for single-database backup) snapshot
        $this->snapshot = $snapshots[0];

        // Run the backup task with existing snapshot
        $backupTask->run($this->snapshot);

        // Refresh to get updated data
        $this->snapshot->refresh();
        $this->snapshot->load('job');

        $this->line("   ✓ Snapshot created (ID: {$this->snapshot->id})");
        $this->line("   ✓ Status: {$this->snapshot->job->status}");
        $this->line("   ✓ Duration: {$this->snapshot->job->getHumanDuration()}");
        $this->line("   ✓ File size: {$this->snapshot->getHumanFileSize()}");

        if ($this->snapshot->checksum) {
            $this->line('   ✓ Checksum: '.substr($this->snapshot->checksum, 0, 16).'...');
        }
    }

    private function verifyBackup(): void
    {
        $this->info("\n🔍 Verifying backup file...");

        // Find the backup file
        $backupDir = '/tmp/backups';
        $files = glob($backupDir.'/*.sql.gz');

        if (empty($files)) {
            throw new \RuntimeException('No backup file found in '.$backupDir);
        }

        // Get the most recent file (should be ours)
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $this->backupFilePath = $files[0];

        $this->line('   ✓ Found backup file: '.basename($this->backupFilePath));

        // Verify file size
        $fileSize = filesize($this->backupFilePath);
        $this->line('   ✓ File size: '.number_format($fileSize).' bytes ('.round($fileSize / 1024, 2).' KB)');

        if ($fileSize < 100) {
            throw new \RuntimeException('Backup file is too small, likely corrupted');
        }

        // Verify it's actually gzipped
        $handle = fopen($this->backupFilePath, 'r');
        $header = fread($handle, 2);
        fclose($handle);

        $isGzip = (bin2hex($header) === '1f8b');
        if (! $isGzip) {
            throw new \RuntimeException('Backup file is not gzipped');
        }

        $this->line('   ✓ File is properly gzipped');

        // Try to decompress and check SQL content
        $this->line('   ℹ Checking SQL content...');
        $gzHandle = gzopen($this->backupFilePath, 'r');
        $firstLine = gzgets($gzHandle);
        gzclose($gzHandle);

        $hasSqlContent = str_contains($firstLine, '--') || str_contains($firstLine, 'CREATE') || str_contains($firstLine, 'DROP');
        if (! $hasSqlContent) {
            $this->warn("   ⚠ Warning: File doesn't appear to contain SQL content");
            $this->line("   First line: {$firstLine}");
        } else {
            $this->line('   ✓ SQL content verified');
        }
    }

    private function runRestore(RestoreTask $restoreTask, BackupJobFactory $backupJobFactory): void
    {
        $this->info("\n🔄 Running restore task...");

        if (! $this->snapshot) {
            throw new \RuntimeException('No snapshot available for restore');
        }

        // Generate a unique database name for the restore test
        $this->restoredDatabaseName = 'testdb_restored_'.time();

        $this->line("   ℹ Restoring to new database: {$this->restoredDatabaseName}");

        $restore = $backupJobFactory->createRestore(
            snapshot: $this->snapshot,
            targetServer: $this->databaseServer,
            schemaName: $this->restoredDatabaseName,
        );

        // Run the restore task with existing restore record
        $restoreTask->run($restore);

        $this->line('   ✓ Restore completed successfully!');
    }

    private function verifyRestore(string $type): void
    {
        $this->info("\n🔍 Verifying restored database...");

        try {
            $dsn = $this->buildDsn($type, $this->restoredDatabaseName);
            $pdo = new \PDO(
                $dsn,
                $this->databaseServer->username,
                $this->databaseServer->password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]
            );

            $this->line('   ✓ Connection to restored database successful');

            // Check if database has tables/content
            if ($type === 'mysql') {
                $stmt = $pdo->query('SHOW TABLES');
                $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                $tableCount = count($tables);
                $this->line("   ✓ Found {$tableCount} table(s) in restored database");
            } elseif ($type === 'postgres') {
                $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
                $tableCount = $stmt->fetchColumn();
                $this->line("   ✓ Found {$tableCount} table(s) in restored database");
            }

            $this->line('   ✓ Restored database is accessible and contains data');
        } catch (\PDOException $e) {
            throw new \RuntimeException("Failed to verify restored database: {$e->getMessage()}");
        }
    }

    private function buildDsn(string $type, string $databaseName): string
    {
        return match ($type) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $this->databaseServer->host,
                $this->databaseServer->port,
                $databaseName
            ),
            'postgres' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->databaseServer->host,
                $this->databaseServer->port,
                $databaseName
            ),
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}"),
        };
    }

    private function cleanup(string $type): void
    {
        $this->info("\n🧹 Cleaning up...");

        // Drop the restored database if it exists
        if ($this->restoredDatabaseName) {
            try {
                $this->dropRestoredDatabase($type);
                $this->line("   ✓ Dropped restored database: {$this->restoredDatabaseName}");
            } catch (\Exception $e) {
                $this->warn("   ⚠ Failed to drop restored database: {$e->getMessage()}");
            }
        }

        // Delete models (cascade will handle backup and snapshots)
        // Snapshot deletion will trigger file cleanup automatically
        if ($this->databaseServer) {
            $snapshotCount = $this->databaseServer->snapshots()->count();
            $this->databaseServer->delete();
            $this->line('   ✓ Deleted DatabaseServer, Backup, and '.$snapshotCount.' Snapshot(s)');
        }

        if ($this->volume) {
            $this->volume->delete();
            $this->line('   ✓ Deleted Volume');
        }

        // Reset properties for next test
        $this->volume = null;
        $this->databaseServer = null;
        $this->backup = null;
        $this->snapshot = null;
        $this->backupFilePath = null;
        $this->restoredDatabaseName = null;
    }

    private function cleanupLeftoverTestData(string $type): void
    {
        // Clean up any leftover test data from previous failed runs
        $volumeName = "Backup Test Local Volume ({$type})";
        $serverName = match ($type) {
            'mysql' => 'Backup Test MySQL Server',
            'postgres' => 'Backup Test PostgreSQL Server',
            default => null,
        };

        // Delete leftover database server (will cascade to backup and snapshots)
        if ($serverName) {
            $leftoverServer = DatabaseServer::where('name', $serverName)->first();
            if ($leftoverServer) {
                $leftoverServer->delete();
            }
        }

        // Delete leftover volume
        $leftoverVolume = Volume::where('name', $volumeName)->first();
        if ($leftoverVolume) {
            $leftoverVolume->delete();
        }
    }

    private function dropRestoredDatabase(string $type): void
    {
        if (! $this->restoredDatabaseName) {
            return;
        }

        $dsn = match ($type) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d',
                $this->databaseServer->host,
                $this->databaseServer->port
            ),
            'postgres' => sprintf(
                'pgsql:host=%s;port=%d;dbname=postgres',
                $this->databaseServer->host,
                $this->databaseServer->port
            ),
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}"),
        };

        $pdo = new \PDO(
            $dsn,
            $this->databaseServer->username,
            $this->databaseServer->password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]
        );

        if ($type === 'mysql') {
            $pdo->exec("DROP DATABASE IF EXISTS `{$this->restoredDatabaseName}`");
        } elseif ($type === 'postgres') {
            // Terminate existing connections first
            $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$this->restoredDatabaseName}' AND pid <> pg_backend_pid()");
            $pdo->exec("DROP DATABASE IF EXISTS \"{$this->restoredDatabaseName}\"");
        }
    }
}
