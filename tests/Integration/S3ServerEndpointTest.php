<?php

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Enums\RunKind;
use App\Enums\SnapshotFileStatus;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\S3Database;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\S3BucketBackupEngine;
use App\Services\Backup\S3BucketRestoreEngine;
use App\Support\FilesystemSupport;
use Illuminate\Support\Str;

/**
 * Live check that an S3 "database server" is wired end-to-end to a real
 * S3-compatible endpoint (the Docker rustfs service). Skips cleanly when the
 * endpoint is not reachable so CI/local runs without the service still pass.
 */
function s3e2eFs(array $config): League\Flysystem\Filesystem
{
    return (new Awss3Filesystem)->get($config);
}

function s3e2eLocalProvider(): FilesystemProvider
{
    $provider = new FilesystemProvider([]);
    $provider->add(new App\Services\Backup\Filesystems\LocalFilesystem);

    return $provider;
}

function s3e2ePersistRun(Snapshot $snapshot, array $outcome, Volume $volume): void
{
    $snapshot->update([
        'run_kind' => $outcome['run_kind'],
        'full_snapshot_id' => $outcome['full_snapshot_id'],
        'filename' => $outcome['filename'],
        'file_size' => $outcome['file_size'],
        'checksum' => $outcome['checksum'],
        'metadata' => [S3BucketBackupEngine::META_STATE_KEY => $outcome['object_state']],
    ]);
    $snapshot->files()->updateOrCreate(
        ['volume_id' => $volume->id],
        ['status' => SnapshotFileStatus::Completed, 'file_exists' => true],
    );
}

it('lists an S3 server bucket folder through the real endpoint', function () {
    $bucket = 's3server-e2e-'.strtolower((string) Str::uuid());
    $creds = [
        'bucket' => $bucket,
        'custom_endpoint' => 'http://rustfs:9000',
        'region' => 'us-east-1',
        'use_path_style_endpoint' => true,
        'access_key_id' => 'rustfsadmin',
        'secret_access_key' => 'rustfsadmin',
    ];
    $awss3 = new Awss3Filesystem;
    $fs = $awss3->get($creds);

    try {
        // S3 does not auto-create buckets; the Flysystem adapter only writes
        // objects. Provision it explicitly so a missing/offline endpoint is
        // reported as a skip rather than a masked NoSuchBucket.
        $awss3->ensureBucketExists($creds);
        $fs->write('photos/one.jpg', 'abc');
        $fs->write('photos/two.png', 'def');
    } catch (\Throwable $e) {
        $this->markTestSkipped('S3 endpoint not reachable: '.$e->getMessage());
    }

    $server = DatabaseServer::factory()->create([
        'name' => 'E2E S3 Bucket',
        'database_type' => DatabaseType::S3->value,
        'host' => 'rustfs',
        'port' => 9000,
        'username' => 'rustfsadmin',
        'password' => 'rustfsadmin',
        'extra_config' => [
            's3_bucket' => $bucket,
            's3_region' => 'us-east-1',
            's3_use_path_style_endpoint' => true,
        ],
    ]);

    $handler = (new DatabaseProvider)->makeForServer($server, '', 'rustfs', 9000);
    expect($handler)->toBeInstanceOf(S3Database::class);

    expect($handler->listDatabases())->toContain('photos');

    try {
        $fs->deleteDirectory('photos');
    } catch (\Throwable) {
        // Best-effort only.
    }
    $awss3->deleteBucket($creds);
});

it('backs up then restores a bucket folder through the real endpoint', function () {
    // Reuse the live-service guard so the test skips when rustfs is offline.
    $bucket = 's3server-restore-'.strtolower((string) Str::uuid());
    $creds = [
        'bucket' => $bucket,
        'custom_endpoint' => 'http://rustfs:9000',
        'region' => 'us-east-1',
        'use_path_style_endpoint' => true,
        'access_key_id' => 'rustfsadmin',
        'secret_access_key' => 'rustfsadmin',
    ];
    $awss3 = new Awss3Filesystem;
    $fs = $awss3->get($creds);

    try {
        $awss3->ensureBucketExists($creds);
        $fs->write('accounts/cust-one.csv', 'row-a');
        $fs->write('accounts/cust-two.csv', 'row-b');
        $fs->write('accounts/temp.csv', 'to-delete-later');
    } catch (\Throwable $e) {
        $this->markTestSkipped('S3 endpoint not reachable: '.$e->getMessage());
    }

    $volume = Volume::factory()->local()->create([
        'name' => 'S3 e2e archive volume',
        'config' => ['root' => FilesystemSupport::createWorkingDirectory('s3e2e-archive', (string) Str::uuid())],
    ]);

    $server = DatabaseServer::factory()->create([
        'name' => 'E2E S3 Restore Source',
        'database_type' => DatabaseType::S3->value,
        'host' => 'rustfs',
        'port' => 9000,
        'username' => 'rustfsadmin',
        'password' => 'rustfsadmin',
        'extra_config' => ['s3_bucket' => $bucket, 's3_region' => 'us-east-1', 's3_use_path_style_endpoint' => true],
    ]);
    $backup = $server->backups()->oldest('id')->firstOrFail();
    $provider = s3e2eLocalProvider();
    $engine = new S3BucketBackupEngine(new CompressorFactory(new App\Services\Backup\ShellProcessor), $provider);

    $run = function (Snapshot $snapshot) use ($engine, $fs, $volume) {
        $out = $engine->run($snapshot, $fs, 'accounts', [VolumeConfig::fromVolume($volume)], $snapshot->job);
        s3e2ePersistRun($snapshot, $out, $volume);

        return $out;
    };

    $mkRun = fn (string $at) => Snapshot::factory()->forServer($server)
        ->create(['backup_id' => $backup->id, 'database_name' => 'accounts', 'compression_type' => CompressionType::GZIP->value, 'started_at' => $at]);

    // ---- full run ----
    $fullOutcome = $run($mkRun(now()->subHour()));
    expect($fullOutcome['run_kind'])->toBe(RunKind::FULL);

    // Mutate between runs: enlarge a tracked file, add one, delete one.
    $fs->write('accounts/cust-one.csv', 'row-a-plus-more');
    $fs->write('accounts/new-user.csv', 'fresh');
    $fs->delete('accounts/temp.csv');

    // ---- incremental ----
    $inc = $mkRun(now());
    $incOutcome = $run($inc);
    expect($incOutcome['run_kind'])->toBe(RunKind::INCREMENTAL)
        ->and($inc->full_snapshot_id)->not->toBeNull();

    // Seed stale objects under the destination scope to prove reconciliation.
    $dst = s3e2eFs($creds);
    $dst->write('restored/obsolete.txt', 'must-be-wiped');
    $dst->write('restored/cust-two.csv', 'stale-v2');

    // ---- restore the incremental state into another folder of the bucket ----
    $restore = new S3BucketRestoreEngine(new CompressorFactory(new App\Services\Backup\ShellProcessor), $provider);
    $restore->restore($inc, $dst, 'restored', $inc->job);

    expect($dst->read('restored/cust-one.csv'))->toBe('row-a-plus-more')
        ->and($dst->read('restored/cust-two.csv'))->toBe('row-b')
        ->and($dst->read('restored/new-user.csv'))->toBe('fresh')
        // deletion dropped, not resurrected
        ->and($dst->fileExists('restored/temp.csv'))->toBeFalse()
        // reconciliation wiped stale objects absent from the selected state
        ->and($dst->fileExists('restored/obsolete.txt'))->toBeFalse()
        // scope never doubled
        ->and($dst->fileExists('restored/accounts/cust-one.csv'))->toBeFalse();

    try {
        $fs->deleteDirectory('accounts');
        $dst->deleteDirectory('restored');
    } catch (\Throwable) {
        // Best-effort only.
    }

    $awss3->deleteBucket($creds);
    FilesystemSupport::cleanupDirectory($volume->config['root']);
});
