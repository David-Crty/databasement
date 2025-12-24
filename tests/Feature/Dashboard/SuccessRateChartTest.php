<?php

use App\Livewire\Dashboard\SuccessRateChart;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('success rate chart builds doughnut chart data', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_name' => 'test_db']);

    // Create jobs with different statuses
    $completedSnapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $completedSnapshots[0]->job->markCompleted();

    $failedSnapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $failedSnapshots[0]->job->markFailed(new Exception('Test'));

    $component = Livewire::actingAs($user)
        ->test(SuccessRateChart::class)
        ->call('load');

    $chart = $component->get('chart');

    expect($chart)->toHaveKey('type', 'doughnut')
        ->and($chart['data']['datasets'][0]['data'])->toHaveCount(4)
        ->and($component->get('total'))->toBe(2);
});

test('success rate chart handles no jobs', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(SuccessRateChart::class)
        ->call('load');

    expect($component->get('total'))->toBe(0);
});
