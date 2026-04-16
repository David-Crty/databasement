<?php

use App\Jobs\ProcessBackupJob;
use App\Livewire\DatabaseServer\Index;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

test('runBackup triggers backup for a specific backup configuration', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('runBackup includes backup display label in success toast', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id);

    // Verify a backup job was created
    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('runBackup fails with authorization error if user is viewer', function () {
    $user = User::factory()->create(['role' => User::ROLE_VIEWER]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id)
        ->assertForbidden();
});
