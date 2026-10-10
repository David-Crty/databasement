<?php

use App\Enums\SnapshotFileStatus;
use App\Livewire\Dashboard\StorageDistributionChart;
use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('storage distribution chart builds doughnut chart data grouped by volume', function () {
    $user = User::factory()->create();

    // Create two servers with different volumes
    $volume1 = Volume::factory()->create(['name' => 'volume-one']);
    $volume2 = Volume::factory()->create(['name' => 'volume-two']);

    $server1 = DatabaseServer::factory()->create(['database_names' => ['db1']]);
    $server1->backups->first()->volumes()->sync([$volume1->id]);

    $server2 = DatabaseServer::factory()->create(['database_names' => ['db2']]);
    $server2->backups->first()->volumes()->sync([$volume2->id]);

    // Create snapshots on different volumes
    $snapshot1 = pendingSnapshot($server1, $user->id);
    $snapshot1->update(['file_size' => 1024 * 1024 * 100]); // 100 MB
    $snapshot1->files()->update(['status' => SnapshotFileStatus::Completed]);

    $snapshot2 = pendingSnapshot($server2, $user->id);
    $snapshot2->update(['file_size' => 1024 * 1024 * 50]); // 50 MB
    $snapshot2->files()->update(['status' => SnapshotFileStatus::Completed]);

    $component = Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(StorageDistributionChart::class);

    $chart = $component->get('chart');

    expect($chart)->toHaveKey('type', 'doughnut')
        ->and($chart['data']['labels'])->toHaveCount(2)
        ->and($chart['data']['datasets'][0]['data'])->toHaveCount(2)
        ->and($component->get('totalBytes'))->toBe(1024 * 1024 * 150);
});

test('storage distribution chart handles no snapshots', function () {
    $user = User::factory()->create();

    $component = Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(StorageDistributionChart::class);

    expect($component->get('totalBytes'))->toBe(0)
        ->and($component->get('chart')['data']['labels'])->toBeEmpty();
});

test('storage distribution chart labels include formatted size', function () {
    $user = User::factory()->create();
    $volume = Volume::factory()->create(['name' => 'my-storage']);
    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $server->backups->first()->volumes()->sync([$volume->id]);

    $snapshot = pendingSnapshot($server, $user->id);
    $snapshot->update(['file_size' => 1024 * 1024 * 256]); // 256 MB
    $snapshot->files()->update(['status' => SnapshotFileStatus::Completed]);

    $component = Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(StorageDistributionChart::class);

    $chart = $component->get('chart');

    expect($chart['data']['labels'][0])->toContain('my-storage')
        ->and($chart['data']['labels'][0])->toContain('256');
});

test('storage distribution chart groups only the current organization', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $volume = Volume::factory()->create(['name' => 'my-storage']);
    $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
    $server->backups->first()->volumes()->sync([$volume->id]);

    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $snapshots[0]->update(['file_size' => 1024]);
    $snapshots[0]->files()->update(['status' => SnapshotFileStatus::Completed]);

    // The joins here bypass nothing: the org scope still has to apply.
    $foreignOrg = Organization::factory()->create();
    Snapshot::factory()
        ->forServer(DatabaseServer::factory()->create(['organization_id' => $foreignOrg->id]))
        ->onVolumes(Volume::factory()->create(['organization_id' => $foreignOrg->id, 'name' => 'foreign-storage']))
        ->create(['file_size' => 999_000]);

    $component = Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(StorageDistributionChart::class);

    expect($component->get('totalBytes'))->toBe(1024)
        ->and($component->get('chart')['data']['labels'])->toHaveCount(1);
});
