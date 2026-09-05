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

test('s3 run deletion is forced newest-first so lineage stays resolvable', function () {
    $full = Snapshot::factory()->forServer($this->server)->create([
        'run_kind' => 'full',
        'database_name' => 'photos',
        'started_at' => now()->subHours(2),
    ]);

    $olderInc = Snapshot::factory()->forServer($this->server)->create([
        'run_kind' => 'incremental',
        'full_snapshot_id' => $full->id,
        'database_name' => 'photos',
        'started_at' => now()->subHour(),
    ]);
    $newestInc = Snapshot::factory()->forServer($this->server)->create([
        'run_kind' => 'incremental',
        'full_snapshot_id' => $full->id,
        'database_name' => 'photos',
        'started_at' => now(),
    ]);

    // The anchor full still has descendents -> cannot be removed.
    expect(fn () => $full->delete())->toThrow(\RuntimeException::class);

    // Older incremental still has a newer sibling -> cannot be removed either.
    expect(fn () => $olderInc->delete())->toThrow(\RuntimeException::class);

    // Newest incremental is the chain tip -> deletion is allowed.
    $newestInc->skipFileCleanup = true; // no real volume file to remove in tests
    $newestInc->delete();

    // Removing the tip unblocks its predecessor, and finally the full anchor.
    $olderInc->skipFileCleanup = true;
    $olderInc->delete();
    $full->skipFileCleanup = true;
    $full->delete();
    $this->addToAssertionCount(1);
});
