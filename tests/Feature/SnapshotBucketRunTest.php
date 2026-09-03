<?php

use App\Models\DatabaseServer;
use App\Models\Snapshot;

beforeEach(function () {
    $this->server = DatabaseServer::factory()->s3()->create();
});

test('an s3 full and incremental snapshot store run tracking and object files', function () {
    $full = Snapshot::factory()
        ->forServer($this->server)
        ->create([
            'run_kind' => 'full',
            'filename' => 'photos/full-001.tar.gz',
            'database_name' => '(root)',
        ]);

    $incremental = Snapshot::factory()
        ->forServer($this->server)
        ->create([
            'run_kind' => 'incremental',
            'full_snapshot_id' => $full->id,
            'filename' => 'photos/inc-001.tar.gz',
            'database_name' => '(root)',
        ]);

    expect($full->run_kind->value)->toBe('full')
        ->and($incremental->run_kind->value)->toBe('incremental')
        ->and($incremental->fullSnapshot->is($full))->toBeTrue();

    $incremental->objectFiles()->create([
        'path' => 'uploads/photo.jpg',
        'size' => 120,
        'mtime' => now(),
    ]);
    $incremental->objectFiles()->create([
        'path' => 'old/file.bin',
        'tombstone' => true,
    ]);

    $objects = $incremental->objectFiles()->get();
    expect($objects)->toHaveCount(2)
        ->and($objects->firstWhere('path', 'uploads/photo.jpg')->size)->toBe(120)
        ->and($objects->firstWhere('path', 'old/file.bin')->tombstone)->toBeTrue();
});
