<?php

use App\Livewire\BackupJob\Index;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('guests cannot access backup jobs index page', function () {
    $this->get(route('jobs.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can access backup jobs index page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('jobs.index'))
        ->assertStatus(200);
});

test('can search backup jobs by server name', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server1 = DatabaseServer::factory()->create(['name' => 'Production MySQL']);
    $server2 = DatabaseServer::factory()->create(['name' => 'Development PostgreSQL']);

    $snapshot1 = $factory->createBackupJob($server1, 'manual', $user->id);
    $snapshot1->job->update(['status' => 'completed']);

    $snapshot2 = $factory->createBackupJob($server2, 'manual', $user->id);
    $snapshot2->job->update(['status' => 'completed']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Production')
        ->assertSee('Production MySQL')
        ->assertDontSee('Development PostgreSQL');
});

test('can filter backup jobs by status', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['name' => 'Test Server']);

    $completedSnapshot = $factory->createBackupJob($server, 'manual', $user->id);
    $completedSnapshot->job->update(['status' => 'completed']);
    $completedSnapshot->update(['database_name' => 'completed_db']);

    $failedSnapshot = $factory->createBackupJob($server, 'scheduled', $user->id);
    $failedSnapshot->job->update(['status' => 'failed']);
    $failedSnapshot->update(['database_name' => 'failed_db']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('statusFilter', 'completed')
        ->assertSee('completed_db')
        ->assertDontSee('failed_db');
});
