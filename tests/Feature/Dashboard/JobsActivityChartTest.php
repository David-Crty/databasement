<?php

use App\Livewire\Dashboard\JobsActivityChart;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('jobs activity chart builds chart data', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_name' => 'test_db']);

    // Create some jobs
    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $snapshots[0]->job->markCompleted();

    $component = Livewire::actingAs($user)
        ->test(JobsActivityChart::class)
        ->call('load');

    $chart = $component->get('chart');

    expect($chart)->toHaveKey('type', 'bar')
        ->and($chart['data'])->toHaveKey('labels')
        ->and($chart['data'])->toHaveKey('datasets')
        ->and($chart['data']['datasets'])->toHaveCount(4); // completed, failed, running, pending
});

test('jobs activity chart has 14 days of data', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(JobsActivityChart::class)
        ->call('load');

    $chart = $component->get('chart');

    expect($chart['data']['labels'])->toHaveCount(14);
});
