<?php

use App\Livewire\Dashboard\LatestJobs;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('latest jobs displays recent jobs', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create([
        'name' => 'Test Server',
        'database_name' => 'test_db',
    ]);

    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $snapshots[0]->job->markCompleted();

    Livewire::actingAs($user)
        ->test(LatestJobs::class)
        ->call('load')
        ->assertSee('Test Server');
});

test('latest jobs can filter by status', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $completedServer = DatabaseServer::factory()->create([
        'name' => 'Completed Server',
        'database_name' => 'completed_db',
    ]);
    $failedServer = DatabaseServer::factory()->create([
        'name' => 'Failed Server',
        'database_name' => 'failed_db',
    ]);

    $completedSnapshots = $factory->createSnapshots($completedServer, 'manual', $user->id);
    $completedSnapshots[0]->job->markCompleted();

    $failedSnapshots = $factory->createSnapshots($failedServer, 'manual', $user->id);
    $failedSnapshots[0]->job->markFailed(new Exception('Test error'));

    Livewire::actingAs($user)
        ->test(LatestJobs::class)
        ->call('load')
        ->assertSee('Completed Server')
        ->assertSee('Failed Server')
        ->set('statusFilter', 'failed')
        ->assertDontSee('Completed Server')
        ->assertSee('Failed Server');
});
