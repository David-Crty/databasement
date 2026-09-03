<?php

use App\Enums\CompressionType;
use App\Enums\RunKind;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\S3BucketBackupEngine;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;

function s3EngineAdapter(string $dir): Filesystem
{
    return new Filesystem(new LocalFilesystemAdapter(
        $dir,
        new PortableVisibilityConverter(defaultForDirectories: Visibility::PUBLIC)
    ), ['visibility' => Visibility::PUBLIC]);
}

beforeEach(function () {
    $this->srcDir = sys_get_temp_dir().'/s3engine-src-'.uniqid();
    $this->tgtDir = sys_get_temp_dir().'/s3engine-tgt-'.uniqid();
    mkdir($this->srcDir, 0755, true);
    mkdir($this->tgtDir, 0755, true);

    $this->volume = Volume::factory()->local()->create([
        'name' => 'Target Bucket Vol',
        'config' => ['path' => $this->tgtDir],
    ]);

    $provider = new App\Services\Backup\Filesystems\FilesystemProvider([]);
    $provider->add(new App\Services\Backup\Filesystems\LocalFilesystem());
    $this->provider = $provider;

    $this->server = DatabaseServer::factory()->s3()->create(['name' => 'Bucket Src']);
});

afterEach(function () {
    foreach ([$this->srcDir, $this->tgtDir] as $dir) {
        if (is_dir($dir)) {
            \App\Support\FilesystemSupport::cleanupDirectory($dir);
        }
    }
});

test('first run against a folder is a full archive uploaded to the volume', function () {
    file_put_contents($this->srcDir.'/a.txt', str_repeat('a', 40));
    mkdir($this->srcDir.'/sub', 0755, true);
    file_put_contents($this->srcDir.'/sub/b.txt', str_repeat('b', 60));

    $backup = $this->server->backups()->oldest('id')->firstOrFail();
    $snapshot = Snapshot::factory()->forServer($this->server)->create([
        'backup_id' => $backup->id,
        'database_name' => '',
        'compression_type' => CompressionType::GZIP->value,
        'filename' => '',
    ]);

    $engine = new S3BucketBackupEngine(
        new App\Services\Backup\Compressors\CompressorFactory(new App\Services\Backup\ShellProcessor()),
        $this->provider,
    );
    $outcome = $engine->run(
        snapshot: $snapshot,
        source: s3EngineAdapter($this->srcDir),
        scope: '',
        targets: [VolumeConfig::fromVolume($this->volume)],
        logger: $snapshot->job,
    );

    expect($outcome['run_kind'])->toBe(RunKind::FULL)
        ->and($outcome['full_snapshot_id'])->toBeNull()
        ->and($outcome['checksum'])->not->toBeEmpty()
        ->and($outcome['file_size'])->toBeGreaterThan(0)
        ->and($outcome['filename'])->toEndWith('.gz');

    // One object row per source file.
    expect(count($outcome['object_files']))->toBe(2);
    expect(array_column($outcome['object_files'], 'path'))->toEqualCanonicalizing(['a.txt', 'sub/b.txt']);
    expect($outcome['object_state'])->toHaveKeys(['a.txt', 'sub/b.txt']);

    // Archive landed on the target volume under the returned filename.
    $files = glob($this->tgtDir.'/*');
    expect(count($files))->toBe(1)
        ->and(basename($files[0]))->toBe($outcome['filename'])
        ->and(filesize($files[0]))->toBe($outcome['file_size']);
});

test('a later run is incremental: archives changes and tombstones deletions', function () {
    // Baseline source: one long-lived file and one that will be deleted.
    file_put_contents($this->srcDir.'/a.txt', str_repeat('a', 40));
    file_put_contents($this->srcDir.'/gone.txt', str_repeat('g', 10));

    $backup = $this->server->backups()->oldest('id')->firstOrFail();
    $engine = new S3BucketBackupEngine(
        new App\Services\Backup\Compressors\CompressorFactory(new App\Services\Backup\ShellProcessor()),
        $this->provider,
    );

    $full = Snapshot::factory()->forServer($this->server)->create([
        'backup_id' => $backup->id,
        'database_name' => '',
        'compression_type' => CompressionType::GZIP->value,
        'started_at' => now()->subHour(),
    ]);
    $fullOutcome = $engine->run(
        snapshot: $full,
        source: s3EngineAdapter($this->srcDir),
        scope: '',
        targets: [],
        logger: $full->job,
    );

    // Simulate the job: make the full run the visible effective baseline.
    $full->update([
        'run_kind' => RunKind::FULL,
        'filename' => $fullOutcome['filename'],
        'checksum' => $fullOutcome['checksum'],
        'file_size' => $fullOutcome['file_size'],
        'metadata' => [S3BucketBackupEngine::META_STATE_KEY => $fullOutcome['object_state']],
    ]);

    // Mutate the source between runs: enlarge a, drop gone, add new c.
    file_put_contents($this->srcDir.'/a.txt', str_repeat('a', 4000));
    unlink($this->srcDir.'/gone.txt');
    file_put_contents($this->srcDir.'/c.txt', 'new-content');

    $inc = Snapshot::factory()->forServer($this->server)->create([
        'backup_id' => $backup->id,
        'database_name' => '',
        'compression_type' => CompressionType::GZIP->value,
        'started_at' => now(),
    ]);
    $incOutcome = $engine->run(
        snapshot: $inc,
        source: s3EngineAdapter($this->srcDir),
        scope: '',
        targets: [],
        logger: $inc->job,
    );

    expect($incOutcome['run_kind'])->toBe(RunKind::INCREMENTAL)
        ->and($incOutcome['full_snapshot_id'])->toBe($full->id)
        ->and($incOutcome['changed_count'])->toBe(2)
        ->and($incOutcome['deleted_count'])->toBe(1)
        ->and($incOutcome['filename'])->toEndWith('.gz');

    // Only the changed objects + the deletion tombstone are archived.
    $rows = $incOutcome['object_files'];
    $paths = array_column($rows, 'path');
    expect($paths)->toEqualCanonicalizing(['a.txt', 'c.txt', 'gone.txt']);

    $tomb = collect($rows)->firstWhere('path', 'gone.txt');
    expect($tomb['tombstone'])->toBeTrue()
        ->and($tomb['size'])->toBeNull()
        ->and(collect($rows)->firstWhere('path', 'c.txt')['tombstone'])->toBeFalse();
});
