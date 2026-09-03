<?php

use App\Enums\CompressionType;
use App\Enums\RunKind;
use App\Enums\SnapshotFileStatus;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\S3BucketBackupEngine;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;

function s3LocalFs(string $dir): Filesystem
{
    return new Filesystem(new LocalFilesystemAdapter(
        $dir,
        new PortableVisibilityConverter(defaultForDirectories: Visibility::PUBLIC)
    ), ['visibility' => Visibility::PUBLIC]);
}

function makeLocalProvider(): FilesystemProvider
{
    $provider = new FilesystemProvider([]);
    $provider->add(new App\Services\Backup\Filesystems\LocalFilesystem());

    return $provider;
}

beforeEach(function () {
    $this->srcDir = sys_get_temp_dir().'/s3rest-src-'.uniqid();
    $this->volDir = sys_get_temp_dir().'/s3rest-vol-'.uniqid();
    $this->dstDir = sys_get_temp_dir().'/s3rest-dst-'.uniqid();
    foreach ([$this->srcDir, $this->volDir, $this->dstDir] as $d) {
        mkdir($d, 0755, true);
    }
    $this->volume = Volume::factory()->local()->create([
        'name' => 'Restore Chain Vol',
        'config' => ['path' => $this->volDir],
    ]);
});

afterEach(function () {
    foreach ([$this->srcDir, $this->volDir, $this->dstDir] as $d) {
        if (is_dir($d)) {
            \App\Support\FilesystemSupport::cleanupDirectory($d);
        }
    }
});

test('restore overlays full + incremental and drops tombstones onto destination', function () {
    $server = DatabaseServer::factory()->s3()->create(['name' => 'Chain Src']);
    $backup = $server->backups()->oldest('id')->firstOrFail();
    $provider = makeLocalProvider();
    $engine = new S3BucketBackupEngine(
        new CompressorFactory(new App\Services\Backup\ShellProcessor()),
        $provider,
    );

    // ---- full run: a.txt + gone.txt ----
    file_put_contents($this->srcDir.'/a.txt', 'aaa');
    file_put_contents($this->srcDir.'/gone.txt', 'gonedata');

    $full = Snapshot::factory()->forServer($server)->create([
        'backup_id' => $backup->id, 'database_name' => '', 'started_at' => now()->subHour(),
        'compression_type' => CompressionType::GZIP->value,
    ]);
    $fullOut = $engine->run($full, s3LocalFs($this->srcDir), '', [VolumeConfig::fromVolume($this->volume)], $full->job);
    persistBucketRun($full, $fullOut, $this->volume);

    // ---- between runs: gone deleted, a enlarged (new version), c added ----
    unlink($this->srcDir.'/gone.txt');
    file_put_contents($this->srcDir.'/a.txt', 'aaaa-longer-content');
    file_put_contents($this->srcDir.'/c.txt', 'ccc');

    $inc = Snapshot::factory()->forServer($server)->create([
        'backup_id' => $backup->id, 'database_name' => '', 'started_at' => now(),
        'compression_type' => CompressionType::GZIP->value,
    ]);
    $incOut = $engine->run($inc, s3LocalFs($this->srcDir), '', [VolumeConfig::fromVolume($this->volume)], $inc->job);
    expect($incOut['run_kind'])->toBe(RunKind::INCREMENTAL);
    persistBucketRun($inc, $incOut, $this->volume);

    // Ensure there are actually two archive copies on the volume.
    expect(count(glob($this->volDir.'/*')))->toBe(2);

    // ---- restore the incremental state back into the destination ----
    $restore = new App\Services\Backup\S3BucketRestoreEngine(
        new CompressorFactory(new App\Services\Backup\ShellProcessor()),
        $provider,
    );
    $restore->restore($inc, s3LocalFs($this->dstDir), '', $inc->job);

    $dstFs = s3LocalFs($this->dstDir);
    expect($dstFs->fileExists('a.txt'))->toBeTrue()
        ->and($dstFs->read('a.txt'))->toBe('aaaa-longer-content')
        ->and($dstFs->fileExists('c.txt'))->toBeTrue()
        // tombstone dropped: gone never resurrected
        ->and($dstFs->fileExists('gone.txt'))->toBeFalse();

    $this->addToAssertionCount(1);
});

test('an older run is restorable as its own point-in-time state', function () {
    $server = DatabaseServer::factory()->s3()->create(['name' => 'Chain Src']);
    $backup = $server->backups()->oldest('id')->firstOrFail();
    $provider = makeLocalProvider();
    $engine = new S3BucketBackupEngine(
        new CompressorFactory(new App\Services\Backup\ShellProcessor()),
        $provider,
    );

    // full: a.txt='aaa', gone.txt
    file_put_contents($this->srcDir.'/a.txt', 'aaa');
    file_put_contents($this->srcDir.'/gone.txt', 'gonedata');
    $full = Snapshot::factory()->forServer($server)->create([
        'backup_id' => $backup->id, 'database_name' => '', 'started_at' => now()->subHour(),
        'compression_type' => CompressionType::GZIP->value,
    ]);
    $fullOut = $engine->run($full, s3LocalFs($this->srcDir), '', [VolumeConfig::fromVolume($this->volume)], $full->job);
    persistBucketRun($full, $fullOut, $this->volume);

    // between runs: modify a, add c, delete gone -> the incremental.
    file_put_contents($this->srcDir.'/a.txt', 'v2-content');
    unlink($this->srcDir.'/gone.txt');
    file_put_contents($this->srcDir.'/c.txt', 'c');
    $inc = Snapshot::factory()->forServer($server)->create([
        'backup_id' => $backup->id, 'database_name' => '', 'started_at' => now(),
        'compression_type' => CompressionType::GZIP->value,
    ]);
    $incOut = $engine->run($inc, s3LocalFs($this->srcDir), '', [VolumeConfig::fromVolume($this->volume)], $inc->job);
    persistBucketRun($inc, $incOut, $this->volume);

    // Restore the FULL (older) point-in-time: original untouched state.
    $restore = new App\Services\Backup\S3BucketRestoreEngine(
        new CompressorFactory(new App\Services\Backup\ShellProcessor()),
        $provider,
    );
    $restore->restore($full, s3LocalFs($this->dstDir), '', $full->job);

    $dstFs = s3LocalFs($this->dstDir);
    expect($dstFs->read('a.txt'))->toBe('aaa')
        ->and($dstFs->fileExists('gone.txt'))->toBeTrue()
        ->and($dstFs->fileExists('c.txt'))->toBeFalse();
    $this->addToAssertionCount(1);
});

function persistBucketRun(Snapshot $snapshot, array $outcome, Volume $volume): void
{
    $snapshot->update([
        'run_kind' => $outcome['run_kind'],
        'full_snapshot_id' => $outcome['full_snapshot_id'],
        'filename' => $outcome['filename'],
        'file_size' => $outcome['file_size'],
        'checksum' => $outcome['checksum'],
        'metadata' => [\App\Services\Backup\S3BucketBackupEngine::META_STATE_KEY => $outcome['object_state']],
    ]);
    $snapshot->files()->updateOrCreate(
        ['volume_id' => $volume->id],
        ['status' => SnapshotFileStatus::Completed, 'file_exists' => true],
    );
}
