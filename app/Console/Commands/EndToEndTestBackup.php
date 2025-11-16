<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
use App\Services\Backup\BackupTask;
use Illuminate\Console\Command;

class EndToEndTestBackup extends Command
{
    protected $signature = 'test:backup-e2e {--type=mysql : Database type (mysql or postgres)}';

    protected $description = 'End-to-end test of the backup system with real databases';

    private ?Volume $volume = null;

    private ?DatabaseServer $databaseServer = null;

    private ?Backup $backup = null;

    private ?string $backupFilePath = null;

    public function handle(BackupTask $backupTask): int
    {
        $type = $this->option('type');

        if (! in_array($type, ['mysql', 'postgres'])) {
            $this->error('Invalid database type. Use --type=mysql or --type=postgres');

            return self::FAILURE;
        }

        $this->info("🧪 Starting end-to-end backup test for {$type}...\n");

        try {
            // Step 1: Setup
            $this->setupTestEnvironment();

            // Step 2: Create models
            $this->createModels($type);

            // Step 3: Run backup
            $this->runBackup($backupTask);

            // Step 4: Verify backup
            $this->verifyBackup();

            // Step 5: Cleanup
            $this->cleanup();

            $this->newLine();
            $this->info('✅ End-to-end backup test completed successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("\n❌ Test failed: {$e->getMessage()}");
            $this->error("Stack trace:\n{$e->getTraceAsString()}");

            // Attempt cleanup even on failure
            try {
                $this->cleanup();
            } catch (\Exception $cleanupError) {
                $this->warn("Cleanup failed: {$cleanupError->getMessage()}");
            }

            return self::FAILURE;
        }
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

        // Create Volume
        $this->volume = Volume::create([
            'name' => 'E2E Test Local Volume',
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
                'host' => '0.0.0.0',
                'port' => 3306,
                'database_type' => 'mysql',
                'username' => 'admin',
                'password' => 'admin',
                'database_name' => 'testdb',
                'description' => 'End-to-end test MySQL database server',
            ],
            'postgres' => [
                'name' => 'E2E Test PostgreSQL Server',
                'host' => '0.0.0.0',
                'port' => 5432,
                'database_type' => 'postgresql',
                'username' => 'admin',
                'password' => 'admin',
                'database_name' => 'testdb',
                'description' => 'End-to-end test PostgreSQL database server',
            ],
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}"),
        };
    }

    private function runBackup(BackupTask $backupTask): void
    {
        $this->info("\n💾 Running backup task...");

        $startTime = microtime(true);
        $backupTask->run($this->databaseServer);
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->line("   ✓ Backup completed in {$duration}ms");
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

    private function cleanup(): void
    {
        $this->info("\n🧹 Cleaning up...");

        // Delete backup file
        if ($this->backupFilePath && file_exists($this->backupFilePath)) {
            unlink($this->backupFilePath);
            $this->line('   ✓ Deleted backup file: '.basename($this->backupFilePath));
        }

        // Delete models (cascade will handle backup)
        if ($this->databaseServer) {
            $this->databaseServer->delete();
            $this->line('   ✓ Deleted DatabaseServer and Backup');
        }

        if ($this->volume) {
            $this->volume->delete();
            $this->line('   ✓ Deleted Volume');
        }
    }
}
