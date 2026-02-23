<?php

use App\Services\Backup\Filesystems\Awss3Filesystem;

test('getPresignedUrl uses public endpoint when configured', function () {
    $filesystem = new Awss3Filesystem;

    $url = $filesystem->getPresignedUrl(
        [
            'bucket' => 'test-bucket',
            'prefix' => 'backups',
            'region' => 'us-east-1',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'custom_endpoint' => 'http://minio:9000',
            'public_endpoint' => 'http://0.0.0.0:9001',
            'use_path_style_endpoint' => true,
        ],
        'file.sql.gz'
    );

    // URL should use public endpoint, not internal
    expect($url)->toStartWith('http://0.0.0.0:9001/test-bucket/backups/file.sql.gz')
        ->and($url)->not->toContain('minio:9000');
});

test('getPresignedUrl uses internal endpoint when no public endpoint configured', function () {
    $filesystem = new Awss3Filesystem;

    $url = $filesystem->getPresignedUrl(
        [
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'custom_endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true,
        ],
        'file.sql.gz'
    );

    // URL should use internal endpoint
    expect($url)->toStartWith('http://minio:9000/test-bucket/file.sql.gz');
});
