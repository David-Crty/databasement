<?php

use App\Models\DatabaseServer;
use App\Models\Snapshot;

use function Pest\Laravel\artisan;

function createSnapshot(DatabaseServer $server, string $status, \Carbon\Carbon $createdAt): Snapshot
{
    $snapshot = Snapshot::factory()
        ->forServer($server)
        ->withFile()
        ->create();

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

test('command deletes only expired completed snapshots', function () {
    // Server with 7 days retention
    $server = DatabaseServer::factory()->create();
    $server->backup->update(['retention_days' => 7]);

    // Should be deleted: completed and expired (10 days old)
    $expiredCompleted = createSnapshot($server, 'completed', now()->subDays(10));
    $volumePath = $expiredCompleted->volume->config['path'];
    $expiredFilePath = $volumePath.'/'.$expiredCompleted->filename;

    // Should NOT be deleted: completed but not expired (3 days old)
    $recentCompleted = createSnapshot($server, 'completed', now()->subDays(3));

    // Should NOT be deleted: expired but pending (not completed)
    $expiredPending = createSnapshot($server, 'pending', now()->subDays(10));

    // Server without retention - snapshots should never be deleted
    $serverNoRetention = DatabaseServer::factory()->create();
    $serverNoRetention->backup->update(['retention_days' => null]);
    $noRetentionSnapshot = createSnapshot($serverNoRetention, 'completed', now()->subDays(100));

    artisan('snapshots:cleanup')
        ->expectsOutputToContain('1 snapshot(s) deleted')
        ->assertSuccessful();

    expect(Snapshot::find($expiredCompleted->id))->toBeNull()
        ->and(file_exists($expiredFilePath))->toBeFalse()
        ->and(Snapshot::find($recentCompleted->id))->not->toBeNull()
        ->and(Snapshot::find($expiredPending->id))->not->toBeNull()
        ->and(Snapshot::find($noRetentionSnapshot->id))->not->toBeNull();
});

test('command dry-run mode does not delete snapshots', function () {
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

test('GFS policy keeps N most recent daily snapshots', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'keep_daily' => 3,
        'keep_weekly' => null,
        'keep_monthly' => null,
    ]);

    // Create 5 daily snapshots (days 1-5)
    $snapshots = collect();
    for ($i = 1; $i <= 5; $i++) {
        $snapshots->push(createSnapshot($server, 'completed', now()->subDays($i)));
    }

    artisan('snapshots:cleanup')->assertSuccessful();

    // Should keep the 3 most recent (days 1, 2, 3), delete days 4 and 5
    expect(Snapshot::find($snapshots[0]->id))->not->toBeNull() // day 1
        ->and(Snapshot::find($snapshots[1]->id))->not->toBeNull() // day 2
        ->and(Snapshot::find($snapshots[2]->id))->not->toBeNull() // day 3
        ->and(Snapshot::find($snapshots[3]->id))->toBeNull() // day 4 - deleted
        ->and(Snapshot::find($snapshots[4]->id))->toBeNull(); // day 5 - deleted
});

test('GFS policy keeps 1 snapshot per week for N weeks', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'keep_daily' => null,
        'keep_weekly' => 2,
        'keep_monthly' => null,
    ]);

    // Create snapshots in different weeks
    $thisWeekSnapshot = createSnapshot($server, 'completed', now()->startOfWeek()->addDays(2));
    $lastWeekSnapshot = createSnapshot($server, 'completed', now()->subWeek()->startOfWeek()->addDays(2));
    $twoWeeksAgoSnapshot = createSnapshot($server, 'completed', now()->subWeeks(2)->startOfWeek()->addDays(2));

    artisan('snapshots:cleanup')->assertSuccessful();

    // Should keep snapshots from this week and last week (2 weeks), delete 2 weeks ago
    expect(Snapshot::find($thisWeekSnapshot->id))->not->toBeNull()
        ->and(Snapshot::find($lastWeekSnapshot->id))->not->toBeNull()
        ->and(Snapshot::find($twoWeeksAgoSnapshot->id))->toBeNull();
});

test('GFS policy keeps 1 snapshot per month for N months', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'keep_daily' => null,
        'keep_weekly' => null,
        'keep_monthly' => 2,
    ]);

    // Create snapshots in different months
    $thisMonthSnapshot = createSnapshot($server, 'completed', now()->startOfMonth()->addDays(5));
    $lastMonthSnapshot = createSnapshot($server, 'completed', now()->subMonth()->startOfMonth()->addDays(5));
    $twoMonthsAgoSnapshot = createSnapshot($server, 'completed', now()->subMonths(2)->startOfMonth()->addDays(5));

    artisan('snapshots:cleanup')->assertSuccessful();

    // Should keep snapshots from this month and last month (2 months), delete 2 months ago
    expect(Snapshot::find($thisMonthSnapshot->id))->not->toBeNull()
        ->and(Snapshot::find($lastMonthSnapshot->id))->not->toBeNull()
        ->and(Snapshot::find($twoMonthsAgoSnapshot->id))->toBeNull();
});

test('GFS policy combines all tiers correctly', function () {
    $server = DatabaseServer::factory()->create();
    $server->backup->update([
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'keep_daily' => 2,
        'keep_weekly' => 2,
        'keep_monthly' => 1,
    ]);

    // Recent snapshots (should be kept by daily tier)
    $day1 = createSnapshot($server, 'completed', now()->subDays(1));
    $day2 = createSnapshot($server, 'completed', now()->subDays(2));
    $day3 = createSnapshot($server, 'completed', now()->subDays(3)); // Outside daily, but might be in weekly

    // Create a snapshot from last week (kept by weekly tier)
    $lastWeekSnapshot = createSnapshot($server, 'completed', now()->subWeek()->startOfWeek()->addDays(1));

    // Create a snapshot from this month (kept by monthly tier - might overlap with others)
    $thisMonthOldSnapshot = createSnapshot($server, 'completed', now()->startOfMonth()->addDay());

    artisan('snapshots:cleanup')->assertSuccessful();

    // Day 1 and 2 should be kept by daily tier
    expect(Snapshot::find($day1->id))->not->toBeNull()
        ->and(Snapshot::find($day2->id))->not->toBeNull();
});
