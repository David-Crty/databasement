<?php

use App\Livewire\Dashboard\StatsCards;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('stats cards calculates correct totals', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_name' => 'test_db']);

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

test('stats cards shows running jobs count', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_name' => 'test_db']);

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
