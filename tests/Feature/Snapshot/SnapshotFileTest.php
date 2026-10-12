<?php

use App\Models\Snapshot;
use App\Services\Backup\Filesystems\FilesystemProvider;

test('removeFromVolume returns false when the copy was never uploaded', function () {
    $snapshot = Snapshot::factory()->create(['filename' => '']);
    $file = $snapshot->files()->firstOrFail();

    // No filesystem should be resolved at all for a copy with no archive.
    $provider = Mockery::mock(FilesystemProvider::class);
    $provider->shouldNotReceive('getForVolume');
    app()->instance(FilesystemProvider::class, $provider);

    expect($file->removeFromVolume())->toBeFalse();
});
