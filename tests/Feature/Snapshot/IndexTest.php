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

    $server1 = DatabaseServer::factory()->create(['name' => 'Production Server']);
    $server2 = DatabaseServer::factory()->create(['name' => 'Staging Server']);

    $snapshot1 = $factory->createBackupJob($server1, 'manual', $user->id);
    $snapshot1->job->update(['status' => 'completed']);

    $snapshot2 = $factory->createBackupJob($server2, 'manual', $user->id);
    $snapshot2->job->update(['status' => 'completed']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Production')
        ->assertSee('Production Server')
        ->assertDontSee('Staging Server');
});

test('can filter snapshots by status', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'Test Server']);

    $completedSnapshot = $factory->createBackupJob($server, 'manual', $user->id);
    $completedSnapshot->job->update(['status' => 'completed']);
    $completedSnapshot->update(['database_name' => 'completed_snapshot']);

    $failedSnapshot = $factory->createBackupJob($server, 'scheduled', $user->id);
    $failedSnapshot->job->update(['status' => 'failed']);
    $failedSnapshot->update(['database_name' => 'failed_snapshot']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('statusFilter', 'completed')
        ->assertSee('completed_snapshot')
        ->assertDontSee('failed_snapshot');
});
