<?php

use App\Livewire\Dashboard\StorageCard;
use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Support\Formatters;
use Livewire\Livewire;

test('storage card calculates total storage', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);

    // Create 3 completed jobs with known file sizes
    for ($i = 0; $i < 3; $i++) {
        $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
        $snapshots[0]->update(['file_size' => 1000]);
        $snapshots[0]->job->markCompleted();
    }

    // Create 1 failed job (should not be counted)
    $failedSnapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $failedSnapshots[0]->update(['file_size' => 500]);
    $failedSnapshots[0]->job->markFailed(new Exception('Test error'));

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(StorageCard::class)
        ->assertSet('totalStorage', '2.93 KB'); // 3 * 1000 = 3000 bytes
});

test('storage card totals only the current organization', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $snapshots[0]->update(['file_size' => 1000]);
    $snapshots[0]->job->markCompleted();

    $foreignOrg = Organization::factory()->create();
    Snapshot::factory()
        ->forServer(DatabaseServer::factory()->create(['organization_id' => $foreignOrg->id]))
        ->onVolumes(Volume::factory()->create(['organization_id' => $foreignOrg->id]))
        ->create(['file_size' => 999_000]);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(StorageCard::class)
        ->assertSet('totalStorage', Formatters::humanFileSize(1000));
});
