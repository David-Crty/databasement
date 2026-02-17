<?php

use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\SnapshotCleanupService;

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
    $server->backup->update(['retention_days' => 7]);

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

test('dry-run mode does not delete snapshots', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update(['retention_days' => 7]);

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
    $this->travelTo(now()->next('Saturday')->setTime(12, 0));

    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'gfs_keep_daily' => 2,
        'gfs_keep_weekly' => 2,
        'gfs_keep_monthly' => 2,
    ]);

    $day1 = createSnapshot($server, 'completed', now()->subDays(1));
    $day2 = createSnapshot($server, 'completed', now()->subDays(2));
    $day3 = createSnapshot($server, 'completed', now()->subDays(3));
    $thisWeekOldest = createSnapshot($server, 'completed', now()->startOfWeek());
    $lastWeek = createSnapshot($server, 'completed', now()->subWeek()->startOfWeek()->addDay());
    $lastMonth = createSnapshot($server, 'completed', now()->subMonth()->startOfMonth()->addDay());

    app(SnapshotCleanupService::class)->run();

    expect(Snapshot::find($day1->id))->not->toBeNull()
        ->and(Snapshot::find($day2->id))->not->toBeNull()
        ->and(Snapshot::find($day3->id))->toBeNull()
        ->and(Snapshot::find($thisWeekOldest->id))->not->toBeNull()
        ->and(Snapshot::find($lastWeek->id))->not->toBeNull()
        ->and(Snapshot::find($lastMonth->id))->not->toBeNull();
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
    $server->backup->update([
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
    $server->backup->update([
        'retention_policy' => 'forever',
        'retention_days' => null,
    ]);

    $recentSnapshot = createSnapshot($server, 'completed', now()->subDays(1));
    $veryOldSnapshot = createSnapshot($server, 'completed', now()->subDays(365));

    app(SnapshotCleanupService::class)->run();

    expect(Snapshot::find($recentSnapshot->id))->not->toBeNull()
        ->and(Snapshot::find($veryOldSnapshot->id))->not->toBeNull();
});
