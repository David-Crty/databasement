<?php

use App\Livewire\Dashboard\SnapshotsCard;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('snapshots card calculates correct total', function () {
    // The dashboard is viewable by any org member — no ability required.
    $user = User::factory()->withAbilities([])->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 3 completed jobs
    for ($i = 0; $i < 3; $i++) {
        $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
        $snapshots[0]->job->markCompleted();
    }

    // Create 1 failed job (should not be counted)
    $failedSnapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $failedSnapshots[0]->job->markFailed(new Exception('Test error'));

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SnapshotsCard::class)
        ->assertSet('totalSnapshots', 3);
});

test('snapshots card shows missing snapshots count', function () {
    $user = User::factory()->withAbilities([])->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 2 snapshots with missing files
    for ($i = 0; $i < 2; $i++) {
        $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
        $snapshots[0]->update(['file_exists' => false, 'file_verified_at' => now()]);
        $snapshots[0]->job->markCompleted();
    }

    // Create 1 normal snapshot
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $snapshots[0]->job->markCompleted();

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SnapshotsCard::class)
        ->assertSet('missingSnapshots', 2)
        ->assertSee('2 missing');
});

test('snapshots card shows all verified when no snapshots are missing', function () {
    $user = User::factory()->withAbilities([])->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 2 verified snapshots (file exists)
    for ($i = 0; $i < 2; $i++) {
        $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
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
