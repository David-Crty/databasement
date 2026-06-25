<?php

use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\SnapshotCleanupService;
use Illuminate\Support\Facades\Log;
use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;

/**
 * @param  array<string, mixed>  $attrs
 */
function updateFirstBackup(DatabaseServer $server, array $attrs): void
{
    $server->backups()->firstOrFail()->update($attrs);
}

function createSnapshot(DatabaseServer $server, string $status, \Carbon\Carbon $createdAt, ?string $databaseName = null): Snapshot
{
    $snapshot = Snapshot::factory()
        ->forServer($server)
        ->withFile()
        ->create($databaseName ? ['database_name' => $databaseName] : []);

    if ($status !== 'completed') {
        $snapshot->job->update([
            'status' => $status,
            'completed_at' => null,
        ]);
    }

    $snapshot->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $snapshot->fresh();
}

test('days retention deletes expired snapshots and files, skips pending and recent', function () {
    $server = DatabaseServer::factory()->create();
    updateFirstBackup($server, ['retention_days' => 7]);

    $expiredCompleted = createSnapshot($server, 'completed', now()->subDays(10), 'app_db');
    $volumePath = $expiredCompleted->volume->config['path'];
    $expiredFilePath = $volumePath.'/'.$expiredCompleted->filename;

    $recentCompleted = createSnapshot($server, 'completed', now()->subDays(3), 'app_db');
    $expiredPending = createSnapshot($server, 'pending', now()->subDays(10), 'app_db');
    $otherDbExpired = createSnapshot($server, 'completed', now()->subDays(10), 'analytics_db');

    $result = app(SnapshotCleanupService::class)->run();

    expect($result['deleted'])->toBe(2)
        ->and($result['dry_run'])->toBeFalse()
        ->and(Snapshot::find($expiredCompleted->id))->toBeNull()
        ->and(file_exists($expiredFilePath))->toBeFalse()
        ->and(Snapshot::find($recentCompleted->id))->not->toBeNull()
        ->and(Snapshot::find($expiredPending->id))->not->toBeNull()
        ->and(Snapshot::find($otherDbExpired->id))->toBeNull();
});

test('deleting a snapshot prunes empty parent folders and stops at the first non-empty one', function (
    string $folder,
    array $extraFiles,
    array $removedDirs,
    array $keptDirs,
) {
    $server = DatabaseServer::factory()->create();
    updateFirstBackup($server, ['retention_days' => 7]);

    $snapshot = createSnapshot($server, 'completed', now()->subDays(10), 'app_db');
    $volumePath = $snapshot->volume->config['path'];

    // Move the backup file into the folder under test, mirroring a path with date placeholders.
    // An empty folder means the snapshot lives directly in the volume root.
    if ($folder !== '') {
        $newFilename = $folder.'/'.$snapshot->filename;
        mkdir($volumePath.'/'.$folder, 0755, true);
        rename($volumePath.'/'.$snapshot->filename, $volumePath.'/'.$newFilename);
        $snapshot->update(['filename' => $newFilename]);
    } else {
        $newFilename = $snapshot->filename;
    }

    // Drop unrelated files that should keep their ancestor folders alive.
    foreach ($extraFiles as $relativePath) {
        $fullPath = $volumePath.'/'.$relativePath;
        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        touch($fullPath);
    }

    app(SnapshotCleanupService::class)->run();

    expect(Snapshot::find($snapshot->id))->toBeNull()
        ->and(file_exists($volumePath.'/'.$newFilename))->toBeFalse()
        // The volume root must never be pruned, even once it becomes empty.
        ->and(is_dir($volumePath))->toBeTrue();

    foreach ($removedDirs as $dir) {
        expect(is_dir($volumePath.'/'.$dir))->toBeFalse("Expected folder '{$dir}' to be removed");
    }

    foreach ($keptDirs as $dir) {
        expect(is_dir($volumePath.'/'.$dir))->toBeTrue("Expected folder '{$dir}' to be kept");
    }

    expect(is_dir($volumePath))->toBeTrue('Volume root must never be pruned');
})->with([
    'snapshot in the volume root leaves the root intact' => [
        'folder' => '',
        'extraFiles' => [],
        'removedDirs' => [],
        'keptDirs' => [],
    ],
    'single empty folder is removed' => [
        'folder' => '2026_06_15',
        'extraFiles' => [],
        'removedDirs' => ['2026_06_15'],
        'keptDirs' => [],
    ],
    'nested empty folders are all removed' => [
        'folder' => '2026/06/15',
        'extraFiles' => [],
        'removedDirs' => ['2026/06/15', '2026/06', '2026'],
        'keptDirs' => [],
    ],
    'folder holding another file is kept' => [
        'folder' => '2026_06_15',
        'extraFiles' => ['2026_06_15/other-backup.sql.gz'],
        'removedDirs' => [],
        'keptDirs' => ['2026_06_15'],
    ],
    'walk stops at an ancestor that still holds a file' => [
        'folder' => '2026/06/15',
        'extraFiles' => ['2026/keep.txt'],
        'removedDirs' => ['2026/06/15', '2026/06'],
        'keptDirs' => ['2026'],
    ],
    'walk stops at an ancestor that holds a sibling subfolder' => [
        'folder' => '2026/06/15',
        'extraFiles' => ['2026/07/older-backup.sql.gz'],
        'removedDirs' => ['2026/06/15', '2026/06'],
        'keptDirs' => ['2026', '2026/07'],
    ],
]);

test('snapshot is still deleted when pruning empty parent folders throws', function () {
    $server = DatabaseServer::factory()->create();
    updateFirstBackup($server, ['retention_days' => 7]);

    $snapshot = createSnapshot($server, 'completed', now()->subDays(10), 'app_db');
    $snapshot->update(['filename' => '2026_06_15/backup.sql.gz']);

    // The file is removed fine, but the volume blows up while pruning the now-empty folder.
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('fileExists')->andReturnTrue();
    $filesystem->shouldReceive('delete')->once();
    $filesystem->shouldReceive('listContents')->andReturn(new DirectoryListing([]));
    $filesystem->shouldReceive('deleteDirectory')->andThrow(new RuntimeException('permission denied'));

    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldReceive('getForVolume')->andReturn($filesystem);
    app()->instance(FilesystemProvider::class, $provider);

    Log::spy();

    app(SnapshotCleanupService::class)->run();

    // The folder failure is swallowed; the snapshot is still removed from the database,
    // and it is logged as a folder-pruning warning rather than a file-deletion error.
    expect(Snapshot::find($snapshot->id))->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'Failed to delete empty parent directory'))
        ->once();
    // The failure must stay warning-only — the file-deletion error path must not fire.
    Log::shouldNotHaveReceived('error');
});

test('dry-run mode does not delete snapshots', function () {
    $server = DatabaseServer::factory()->create();
    updateFirstBackup($server, ['retention_days' => 7]);

    $expiredSnapshot = createSnapshot($server, 'completed', now()->subDays(10));
    $volumePath = $expiredSnapshot->volume->config['path'];
    $filePath = $volumePath.'/'.$expiredSnapshot->filename;

    $result = app(SnapshotCleanupService::class)->run(dryRun: true);

    expect($result['deleted'])->toBe(1)
        ->and($result['dry_run'])->toBeTrue()
        ->and(Snapshot::find($expiredSnapshot->id))->not->toBeNull()
        ->and(file_exists($filePath))->toBeTrue();
});

test('GFS retention combines daily, weekly, and monthly tiers', function () {
    $this->travelTo(\Carbon\Carbon::create(2026, 6, 20, 12, 0));

    $server = DatabaseServer::factory()->create();
    updateFirstBackup($server, [
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'gfs_keep_daily' => 2,
        'gfs_keep_weekly' => 2,
        'gfs_keep_monthly' => 2,
    ]);

    // Daily tier keeps the 2 most recent regardless of period.
    $day1 = createSnapshot($server, 'completed', now()->subDays(1));
    $day2 = createSnapshot($server, 'completed', now()->subDays(2));
    $day3 = createSnapshot($server, 'completed', now()->subDays(3));

    // Last week has two snapshots — only the newest one should be kept by the weekly tier.
    $lastWeekMid = createSnapshot($server, 'completed', now()->subWeek()->startOfWeek()->addDay());
    $lastWeekNewest = createSnapshot($server, 'completed', now()->subWeek()->endOfWeek()->subHour());

    // Last month has two snapshots — only the newest one should be kept by the monthly tier.
    $lastMonthMid = createSnapshot($server, 'completed', now()->subMonth()->startOfMonth()->addDay());
    $lastMonthNewest = createSnapshot($server, 'completed', now()->subMonth()->endOfMonth()->subHour());

    app(SnapshotCleanupService::class)->run();

    expect(Snapshot::find($day1->id))->not->toBeNull()
        ->and(Snapshot::find($day2->id))->not->toBeNull()
        ->and(Snapshot::find($day3->id))->toBeNull()
        ->and(Snapshot::find($lastWeekMid->id))->toBeNull()
        ->and(Snapshot::find($lastWeekNewest->id))->not->toBeNull()
        ->and(Snapshot::find($lastMonthMid->id))->toBeNull()
        ->and(Snapshot::find($lastMonthNewest->id))->not->toBeNull();
});

test('GFS retention applies per database_name', function () {
    $server = DatabaseServer::factory()->create();
    updateFirstBackup($server, [
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'gfs_keep_daily' => 2,
        'gfs_keep_weekly' => null,
        'gfs_keep_monthly' => null,
    ]);

    $appDb1 = createSnapshot($server, 'completed', now()->subDays(1), 'app_db');
    $appDb2 = createSnapshot($server, 'completed', now()->subDays(2), 'app_db');
    $appDb3 = createSnapshot($server, 'completed', now()->subDays(3), 'app_db');

    $analyticsDb1 = createSnapshot($server, 'completed', now()->subDays(1), 'analytics_db');
    $analyticsDb2 = createSnapshot($server, 'completed', now()->subDays(2), 'analytics_db');
    $analyticsDb3 = createSnapshot($server, 'completed', now()->subDays(3), 'analytics_db');

    app(SnapshotCleanupService::class)->run();

    expect(Snapshot::find($appDb1->id))->not->toBeNull()
        ->and(Snapshot::find($appDb2->id))->not->toBeNull()
        ->and(Snapshot::find($appDb3->id))->toBeNull()
        ->and(Snapshot::find($analyticsDb1->id))->not->toBeNull()
        ->and(Snapshot::find($analyticsDb2->id))->not->toBeNull()
        ->and(Snapshot::find($analyticsDb3->id))->toBeNull();
});

test('GFS with no tiers configured skips cleanup', function () {
    $server = DatabaseServer::factory()->create();
    updateFirstBackup($server, [
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'gfs_keep_daily' => null,
        'gfs_keep_weekly' => null,
        'gfs_keep_monthly' => null,
    ]);

    $oldSnapshot = createSnapshot($server, 'completed', now()->subDays(100));

    app(SnapshotCleanupService::class)->run();

    expect(Snapshot::find($oldSnapshot->id))->not->toBeNull();
});

test('forever policy keeps all snapshots indefinitely', function () {
    $server = DatabaseServer::factory()->create();
    updateFirstBackup($server, [
        'retention_policy' => 'forever',
        'retention_days' => null,
    ]);

    $recentSnapshot = createSnapshot($server, 'completed', now()->subDays(1));
    $veryOldSnapshot = createSnapshot($server, 'completed', now()->subDays(365));

    app(SnapshotCleanupService::class)->run();

    expect(Snapshot::find($recentSnapshot->id))->not->toBeNull()
        ->and(Snapshot::find($veryOldSnapshot->id))->not->toBeNull();
});
