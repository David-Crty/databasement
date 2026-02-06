<?php

use App\Jobs\VerifySnapshotFileJob;
use App\Livewire\Dashboard\StatsCards;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('stats cards calculates correct totals', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 3 completed jobs
    for ($i = 0; $i < 3; $i++) {
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
        $snapshots[0]->update(['file_size' => 1000]);
        $snapshots[0]->job->markCompleted();
    }

    // Create 1 failed job
    $failedSnapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $failedSnapshots[0]->update(['file_size' => 500]);
    $failedSnapshots[0]->job->markFailed(new Exception('Test error'));

    Livewire::actingAs($user)
        ->test(StatsCards::class)
        ->call('load')
        ->assertSet('totalSnapshots', 4)
        ->assertSet('successRate', 75.0); // 3 out of 4 = 75%
});

test('stats cards shows missing snapshots count', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 2 snapshots with missing files
    for ($i = 0; $i < 2; $i++) {
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
        $snapshots[0]->update(['file_exists' => false, 'file_verified_at' => now()]);
        $snapshots[0]->job->markCompleted();
    }

    // Create 1 normal snapshot
    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $snapshots[0]->job->markCompleted();

    Livewire::actingAs($user)
        ->test(StatsCards::class)
        ->call('load')
        ->assertSet('missingSnapshots', 2)
        ->assertSee('2 missing');
});

test('stats cards shows all verified when no snapshots are missing', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 2 verified snapshots (file exists)
    for ($i = 0; $i < 2; $i++) {
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
        $snapshots[0]->update(['file_exists' => true, 'file_verified_at' => now()]);
        $snapshots[0]->job->markCompleted();
    }

    Livewire::actingAs($user)
        ->test(StatsCards::class)
        ->call('load')
        ->assertSet('verifiedSnapshots', 2)
        ->assertSet('missingSnapshots', 0)
        ->assertSee('All verified');
});

test('stats cards shows running jobs count', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 2 running jobs
    for ($i = 0; $i < 2; $i++) {
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
        $snapshots[0]->job->markRunning();
    }

    Livewire::actingAs($user)
        ->test(StatsCards::class)
        ->call('load')
        ->assertSet('runningJobs', 2);
});

test('verify files button dispatches verification job', function () {
    Queue::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsCards::class)
        ->call('verifyFiles');

    Queue::assertPushed(VerifySnapshotFileJob::class, 1);
    Queue::assertPushed(VerifySnapshotFileJob::class, fn ($job) => $job->snapshotId === null);
});

test('verify files button prevents rapid re-dispatch via cache lock', function () {
    Queue::fake();

    $user = User::factory()->create();

    Cache::lock('verify-snapshot-files', 300)->get();

    Livewire::actingAs($user)
        ->test(StatsCards::class)
        ->call('verifyFiles');

    Queue::assertNothingPushed();
});
