<?php

use App\Livewire\Dashboard\StorageDistributionChart;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('storage distribution chart builds doughnut chart data grouped by volume', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    // Create two servers with different volumes
    $volume1 = Volume::factory()->create(['name' => 'volume-one']);
    $volume2 = Volume::factory()->create(['name' => 'volume-two']);

    $server1 = DatabaseServer::factory()->create(['database_name' => 'db1']);
    $server1->backup->update(['volume_id' => $volume1->id]);

    $server2 = DatabaseServer::factory()->create(['database_name' => 'db2']);
    $server2->backup->update(['volume_id' => $volume2->id]);

    // Create snapshots on different volumes
    $snapshots1 = $factory->createSnapshots($server1, 'manual', $user->id);
    $snapshots1[0]->update(['file_size' => 1024 * 1024 * 100]); // 100 MB

    $snapshots2 = $factory->createSnapshots($server2, 'manual', $user->id);
    $snapshots2[0]->update(['file_size' => 1024 * 1024 * 50]); // 50 MB

    $component = Livewire::actingAs($user)
        ->test(StorageDistributionChart::class)
        ->call('load');

    $chart = $component->get('chart');

    expect($chart)->toHaveKey('type', 'doughnut')
        ->and($chart['data']['labels'])->toHaveCount(2)
        ->and($chart['data']['datasets'][0]['data'])->toHaveCount(2)
        ->and($component->get('totalBytes'))->toBe(1024 * 1024 * 150);
});

test('storage distribution chart handles no snapshots', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(StorageDistributionChart::class)
        ->call('load');

    expect($component->get('totalBytes'))->toBe(0)
        ->and($component->get('chart')['data']['labels'])->toBeEmpty();
});

test('storage distribution chart labels include formatted size', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $volume = Volume::factory()->create(['name' => 'my-storage']);
    $server = DatabaseServer::factory()->create(['database_name' => 'test_db']);
    $server->backup->update(['volume_id' => $volume->id]);

    $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
    $snapshots[0]->update(['file_size' => 1024 * 1024 * 256]); // 256 MB

    $component = Livewire::actingAs($user)
        ->test(StorageDistributionChart::class)
        ->call('load');

    $chart = $component->get('chart');

    expect($chart['data']['labels'][0])->toContain('my-storage')
        ->and($chart['data']['labels'][0])->toContain('256');
});
