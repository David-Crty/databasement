<?php

use App\Models\DatabaseServer;
use App\Enums\DatabaseType;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\S3Database;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use Illuminate\Support\Str;

/**
 * Live check that an S3 "database server" is wired end-to-end to a real
 * S3-compatible endpoint (the Docker rustfs service). Skips cleanly when the
 * endpoint is not reachable so CI/local runs without the service still pass.
 */
it('lists an S3 server bucket folder through the real endpoint', function () {
    $bucket = 's3server-e2e-'.strtolower((string) Str::uuid());
    $fs = (new Awss3Filesystem)->get([
        'bucket' => $bucket,
        'custom_endpoint' => 'http://rustfs:9000',
        'region' => 'us-east-1',
        'use_path_style_endpoint' => true,
        'access_key_id' => 'rustfsadmin',
        'secret_access_key' => 'rustfsadmin',
    ]);

    try {
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
});
