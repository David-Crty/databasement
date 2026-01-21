<?php

use App\Models\DatabaseServer;
use App\Models\Snapshot;

use function Pest\Laravel\artisan;

function createSnapshot(DatabaseServer $server, string $status, \Carbon\Carbon $createdAt, ?string $databaseName = null): Snapshot
{
    $snapshot = Snapshot::factory()
        ->forServer($server)
        ->withFile()
        ->create($databaseName ? ['database_name' => $databaseName] : []);

    // Update job status if not 'completed'
    if ($status !== 'completed') {
        $snapshot->job->update([
            'status' => $status,
            'completed_at' => null,
        ]);
    }

    // Override created_at for retention testing
    $snapshot->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $snapshot->fresh();
}

test('days retention deletes expired snapshots and files, skips pending and recent', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update(['retention_days' => 7]);

    // Should be deleted: completed and expired (10 days old)
    $expiredCompleted = createSnapshot($server, 'completed', now()->subDays(10), 'app_db');
    $volumePath = $expiredCompleted->volume->config['path'];
    $expiredFilePath = $volumePath.'/'.$expiredCompleted->filename;

    // Should NOT be deleted: completed but not expired (3 days old)
    $recentCompleted = createSnapshot($server, 'completed', now()->subDays(3), 'app_db');

    // Should NOT be deleted: expired but pending (not completed)
    $expiredPending = createSnapshot($server, 'pending', now()->subDays(10), 'app_db');

    // Should be deleted: expired snapshot from different database
    $otherDbExpired = createSnapshot($server, 'completed', now()->subDays(10), 'analytics_db');

    artisan('snapshots:cleanup')->assertSuccessful();

    expect(Snapshot::find($expiredCompleted->id))->toBeNull()
        ->and(file_exists($expiredFilePath))->toBeFalse()
        ->and(Snapshot::find($recentCompleted->id))->not->toBeNull()
        ->and(Snapshot::find($expiredPending->id))->not->toBeNull()
        ->and(Snapshot::find($otherDbExpired->id))->toBeNull();
});

test('dry-run mode does not delete snapshots', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update(['retention_days' => 7]);

    $expiredSnapshot = createSnapshot($server, 'completed', now()->subDays(10));
    $volumePath = $expiredSnapshot->volume->config['path'];
    $filePath = $volumePath.'/'.$expiredSnapshot->filename;

    artisan('snapshots:cleanup', ['--dry-run' => true])
        ->expectsOutput('Running in dry-run mode. No snapshots will be deleted.')
        ->expectsOutputToContain('1 snapshot(s) would be deleted')
        ->assertSuccessful();

    expect(Snapshot::find($expiredSnapshot->id))->not->toBeNull()
        ->and(file_exists($filePath))->toBeTrue();
});

test('GFS retention combines daily, weekly, and monthly tiers', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'gfs_keep_daily' => 2,
        'gfs_keep_weekly' => 2,
        'gfs_keep_monthly' => 1,
    ]);

    // Recent snapshots (should be kept by daily tier)
    $day1 = createSnapshot($server, 'completed', now()->subDays(1));
    $day2 = createSnapshot($server, 'completed', now()->subDays(2));
    $day3 = createSnapshot($server, 'completed', now()->subDays(3)); // Outside daily tier

    // Snapshot at start of this week (kept by weekly tier as oldest in week)
    $thisWeekOldest = createSnapshot($server, 'completed', now()->startOfWeek());

    // Snapshot from last week (kept by weekly tier)
    $lastWeek = createSnapshot($server, 'completed', now()->subWeek()->startOfWeek()->addDay());

    // Snapshot from this month (kept by monthly tier)
    $thisMonth = createSnapshot($server, 'completed', now()->startOfMonth()->addDay());

    artisan('snapshots:cleanup')->assertSuccessful();

    // Daily tier keeps day 1 and 2
    expect(Snapshot::find($day1->id))->not->toBeNull()
        ->and(Snapshot::find($day2->id))->not->toBeNull()
        // Day 3 outside daily, not oldest in week
        ->and(Snapshot::find($day3->id))->toBeNull()
        // Weekly tier keeps oldest from this week and last week
        ->and(Snapshot::find($thisWeekOldest->id))->not->toBeNull()
        ->and(Snapshot::find($lastWeek->id))->not->toBeNull()
        // Monthly tier keeps this month
        ->and(Snapshot::find($thisMonth->id))->not->toBeNull();
});

test('GFS retention applies per database_name', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'gfs_keep_daily' => 2,
        'gfs_keep_weekly' => null,
        'gfs_keep_monthly' => null,
    ]);

    // Create 3 snapshots for database "app_db"
    $appDb1 = createSnapshot($server, 'completed', now()->subDays(1), 'app_db');
    $appDb2 = createSnapshot($server, 'completed', now()->subDays(2), 'app_db');
    $appDb3 = createSnapshot($server, 'completed', now()->subDays(3), 'app_db');

    // Create 3 snapshots for database "analytics_db"
    $analyticsDb1 = createSnapshot($server, 'completed', now()->subDays(1), 'analytics_db');
    $analyticsDb2 = createSnapshot($server, 'completed', now()->subDays(2), 'analytics_db');
    $analyticsDb3 = createSnapshot($server, 'completed', now()->subDays(3), 'analytics_db');

    artisan('snapshots:cleanup')->assertSuccessful();

    // Each database keeps its own 2 most recent, deletes the 3rd
    expect(Snapshot::find($appDb1->id))->not->toBeNull()
        ->and(Snapshot::find($appDb2->id))->not->toBeNull()
        ->and(Snapshot::find($appDb3->id))->toBeNull()
        ->and(Snapshot::find($analyticsDb1->id))->not->toBeNull()
        ->and(Snapshot::find($analyticsDb2->id))->not->toBeNull()
        ->and(Snapshot::find($analyticsDb3->id))->toBeNull();
});

test('GFS with no tiers configured skips cleanup', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'gfs_keep_daily' => null,
        'gfs_keep_weekly' => null,
        'gfs_keep_monthly' => null,
    ]);

    $oldSnapshot = createSnapshot($server, 'completed', now()->subDays(100));

    artisan('snapshots:cleanup')
        ->expectsOutputToContain('GFS policy has no tiers configured, skipping cleanup')
        ->assertSuccessful();

    expect(Snapshot::find($oldSnapshot->id))->not->toBeNull();
});

test('forever policy keeps all snapshots indefinitely', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'forever',
        'retention_days' => null,
    ]);

    $recentSnapshot = createSnapshot($server, 'completed', now()->subDays(1));
    $veryOldSnapshot = createSnapshot($server, 'completed', now()->subDays(365));

    artisan('snapshots:cleanup')->assertSuccessful();

    expect(Snapshot::find($recentSnapshot->id))->not->toBeNull()
        ->and(Snapshot::find($veryOldSnapshot->id))->not->toBeNull();
});
