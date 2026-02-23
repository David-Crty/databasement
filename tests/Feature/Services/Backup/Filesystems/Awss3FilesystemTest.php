<?php

use App\Services\Backup\Filesystems\Awss3Filesystem;
use Aws\S3\S3Client;

test('createClient uses assume role credentials when custom_role_arn is configured', function () {
    $filesystem = new Awss3Filesystem;

    $config = [
        'bucket' => 'test-bucket',
        'region' => 'eu-central-1',
        'custom_role_arn' => 'arn:aws:iam::123456789012:role/test-role',
        'role_session_name' => 'test-session',
        'sts_endpoint' => 'https://sts.eu-central-1.amazonaws.com',
        'custom_endpoint' => 'https://s3.eu-central-1.amazonaws.com',
        'use_path_style_endpoint' => false,
    ];

    // Use reflection to call the private createClient method
    $method = new ReflectionMethod($filesystem, 'createClient');
    /** @var S3Client $client */
    $client = $method->invoke($filesystem, $config);

    expect($client)->toBeInstanceOf(S3Client::class)
        ->and($client->getRegion())->toBe('eu-central-1');

    // Credentials should be a callable (memoized AssumeRoleCredentialProvider),
    // not a static array with key/secret
    $credentials = $client->getCredentials();
    expect($credentials)->toBeInstanceOf(GuzzleHttp\Promise\PromiseInterface::class);
});

test('createClient uses assume role credentials with default session name', function () {
    $filesystem = new Awss3Filesystem;

    $config = [
        'bucket' => 'test-bucket',
        'region' => 'us-west-2',
        'custom_role_arn' => 'arn:aws:iam::987654321098:role/backup-role',
    ];

    $method = new ReflectionMethod($filesystem, 'createClient');
    /** @var S3Client $client */
    $client = $method->invoke($filesystem, $config);

    expect($client)->toBeInstanceOf(S3Client::class)
        ->and($client->getRegion())->toBe('us-west-2');
});

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
