<?php

use App\Livewire\Snapshot\Index;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('guests cannot access snapshots index page', function () {
    $this->get(route('snapshots.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can access snapshots index page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('snapshots.index'))
        ->assertStatus(200);
});

test('can search snapshots by server name', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['name' => 'Production Server', 'database_name' => 'production_db']);
    $server2 = DatabaseServer::factory()->create(['name' => 'Staging Server', 'database_name' => 'staging_db']);

    $snapshots1 = $factory->createSnapshots($server1, 'manual', $user->id);
    $snapshots1[0]->job->update(['status' => 'completed']);

    $snapshots2 = $factory->createSnapshots($server2, 'manual', $user->id);
    $snapshots2[0]->job->update(['status' => 'completed']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Production')
        ->assertSee('Production Server')
        ->assertDontSee('Staging Server');
});

test('can filter snapshots by status', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'Test Server', 'database_name' => 'test_db']);

    $completedSnapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $completedSnapshot = $completedSnapshots[0];
    $completedSnapshot->job->update(['status' => 'completed']);
    $completedSnapshot->update(['database_name' => 'completed_snapshot']);

    $failedSnapshots = $factory->createSnapshots($server, 'scheduled', $user->id);
    $failedSnapshot = $failedSnapshots[0];
    $failedSnapshot->job->update(['status' => 'failed']);
    $failedSnapshot->update(['database_name' => 'failed_snapshot']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('statusFilter', 'completed')
        ->assertSee('completed_snapshot')
        ->assertDontSee('failed_snapshot');
});
