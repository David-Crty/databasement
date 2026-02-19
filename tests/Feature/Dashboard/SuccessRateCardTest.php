<?php

use App\Livewire\Dashboard\SuccessRateCard;
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
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
        $snapshots[0]->job->markCompleted();
    }

    // Create 1 failed job
    $failedSnapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $failedSnapshots[0]->job->markFailed(new Exception('Test error'));

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SuccessRateCard::class)
        ->assertSet('successRate', 75.0); // 3 out of 4 = 75%
});

test('success rate card shows running jobs count', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 2 running jobs
    for ($i = 0; $i < 2; $i++) {
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
        $snapshots[0]->job->markRunning();
    }

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(SuccessRateCard::class)
        ->assertSet('runningJobs', 2);
});
