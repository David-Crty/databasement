<?php

use App\Jobs\VerifySnapshotFileJob;
use App\Livewire\Dashboard\SnapshotsCard;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('snapshots card calculates correct total', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 3 completed jobs
    for ($i = 0; $i < 3; $i++) {
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
        $snapshots[0]->job->markCompleted();
    }

    // Create 1 failed job (should not be counted)
    $failedSnapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $failedSnapshots[0]->job->markFailed(new Exception('Test error'));

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SnapshotsCard::class)
        ->assertSet('totalSnapshots', 3);
});

test('snapshots card shows missing snapshots count', function () {
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

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SnapshotsCard::class)
        ->assertSet('missingSnapshots', 2)
        ->assertSee('2 missing');
});

test('snapshots card shows all verified when no snapshots are missing', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 2 verified snapshots (file exists)
    for ($i = 0; $i < 2; $i++) {
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
        $snapshots[0]->update(['file_exists' => true, 'file_verified_at' => now()]);
        $snapshots[0]->job->markCompleted();
    }

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SnapshotsCard::class)
        ->assertSet('verifiedSnapshots', 2)
        ->assertSet('missingSnapshots', 0)
        ->assertSee('All verified');
});

test('verify files button dispatches verification job', function () {
    Queue::fake();

    $admin = User::factory()->create(['role' => 'admin']);

    Livewire::withoutLazyLoading()
        ->actingAs($admin)
        ->test(SnapshotsCard::class)
        ->call('verifyFiles');

    Queue::assertPushed(VerifySnapshotFileJob::class, 1);
});

test('verify files button prevents rapid re-dispatch via cache lock', function () {
    Queue::fake();

    $admin = User::factory()->create(['role' => 'admin']);

    Cache::lock('verify-snapshot-files', 300)->get();

    Livewire::withoutLazyLoading()
        ->actingAs($admin)
        ->test(SnapshotsCard::class)
        ->call('verifyFiles');

    Queue::assertNothingPushed();
});

test('non-admin cannot dispatch verification job', function () {
    Queue::fake();

    $user = User::factory()->create();

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SnapshotsCard::class)
        ->call('verifyFiles')
        ->assertForbidden();

    Queue::assertNothingPushed();
});
