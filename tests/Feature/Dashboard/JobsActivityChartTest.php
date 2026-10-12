<?php

use App\Livewire\Dashboard\JobsActivityChart;
use App\Models\DatabaseServer;
use App\Models\User;
use Livewire\Livewire;

test('jobs activity chart builds chart data', function () {
    $user = User::factory()->create();

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create some jobs
    $snapshot = pendingSnapshot($server, $user->id);
    $snapshot->job->markCompleted();

    $component = Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(JobsActivityChart::class);

    $chart = $component->get('chart');

    expect($chart)->toHaveKey('type', 'bar')
        ->and($chart['data'])->toHaveKey('labels')
        ->and($chart['data'])->toHaveKey('datasets')
        ->and($chart['data']['datasets'])->toHaveCount(4); // completed, failed, running, pending
});

test('jobs activity chart has 14 days of data', function () {
    $user = User::factory()->create();

    $component = Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(JobsActivityChart::class);

    $chart = $component->get('chart');

    expect($chart['data']['labels'])->toHaveCount(14);
});
