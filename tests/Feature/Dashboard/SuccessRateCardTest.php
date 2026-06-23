<?php

use App\Livewire\Dashboard\SuccessRateCard;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('success rate card calculates correct rate', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 3 completed jobs
    for ($i = 0; $i < 3; $i++) {
        $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
        $snapshots[0]->job->markCompleted();
    }

    // Create 1 failed job
    $failedSnapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $failedSnapshots[0]->job->markFailed(new Exception('Test error'));

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SuccessRateCard::class)
        ->assertSet('successRate', 75.0); // 3 out of 4 = 75%
});

test('success rate resets to zero when a refresh finds no completed or failed jobs', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $snapshots[0]->job->markCompleted();

    $component = Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SuccessRateCard::class)
        ->assertSet('successRate', 100.0);

    // The only counted job ages out of the 30-day window; a refresh must not keep the stale rate.
    BackupJob::where('id', $snapshots[0]->job->id)->update(['created_at' => now()->subDays(31)]);

    $component->call('refreshDashboard')
        ->assertSet('successRate', 0.0);
});

test('success rate card shows running jobs count', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 2 running jobs
    for ($i = 0; $i < 2; $i++) {
        $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
        $snapshots[0]->job->markRunning();
    }

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SuccessRateCard::class)
        ->assertSet('runningJobs', 2);
});
