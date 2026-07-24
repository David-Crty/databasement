<?php

use App\Services\Backup\Filesystems\AzureFilesystem;
use League\Flysystem\Filesystem;

function azureFilesystemWithExposedConnectionString(): AzureFilesystem
{
    return new class extends AzureFilesystem
    {
        /** @param  array<string, mixed>  $config */
        public function exposeConnectionString(array $config): string
        {
            return $this->buildConnectionString($config);
        }
    };
}

test('handles only the azure type', function () {
    $filesystem = new AzureFilesystem;

    expect($filesystem->handles('azure'))->toBeTrue()
        ->and($filesystem->handles('AZURE'))->toBeTrue()
        ->and($filesystem->handles('s3'))->toBeFalse()
        ->and($filesystem->handles(null))->toBeFalse();
});

test('builds a public connection string from the account and endpoint suffix', function () {
    $connectionString = azureFilesystemWithExposedConnectionString()->exposeConnectionString([
        'account_name' => 'myaccount',
        'account_key' => 'secret-key',
        'endpoint_suffix' => 'core.usgovcloudapi.net',
    ]);

    expect($connectionString)->toBe(
        'DefaultEndpointsProtocol=https;AccountName=myaccount;AccountKey=secret-key;EndpointSuffix=core.usgovcloudapi.net'
    );
});

test('defaults the endpoint suffix when none is provided', function () {
    $connectionString = azureFilesystemWithExposedConnectionString()->exposeConnectionString([
        'account_name' => 'myaccount',
        'account_key' => 'secret-key',
    ]);

    expect($connectionString)->toContain('EndpointSuffix=core.windows.net');
});

test('a custom endpoint overrides the suffix and derives the protocol from its scheme', function () {
    $connectionString = azureFilesystemWithExposedConnectionString()->exposeConnectionString([
        'account_name' => 'devstoreaccount1',
        'account_key' => 'secret-key',
        'endpoint_suffix' => 'core.windows.net',
        'endpoint' => 'http://azurite:10000/devstoreaccount1',
    ]);

    expect($connectionString)->toBe(
        'DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=secret-key;BlobEndpoint=http://azurite:10000/devstoreaccount1'
    )->and($connectionString)->not->toContain('EndpointSuffix');
});

test('an https custom endpoint keeps the https protocol', function () {
    $connectionString = azureFilesystemWithExposedConnectionString()->exposeConnectionString([
        'account_name' => 'myaccount',
        'account_key' => 'secret-key',
        'endpoint' => 'https://gateway.example.com/myaccount',
    ]);

    expect($connectionString)->toStartWith('DefaultEndpointsProtocol=https;')
        ->and($connectionString)->toContain('BlobEndpoint=https://gateway.example.com/myaccount');
});

test('get builds a Flysystem filesystem for a public-cloud config', function () {
    $filesystem = (new AzureFilesystem)->get([
        'account_name' => 'myaccount',
        'account_key' => base64_encode('secret'),
        'container' => 'backups',
        'prefix' => 'production/',
        'endpoint_suffix' => 'core.windows.net',
    ]);

    expect($filesystem)->toBeInstanceOf(Filesystem::class);
});

test('get builds a Flysystem filesystem when a custom endpoint is set', function () {
    $filesystem = (new AzureFilesystem)->get([
        'account_name' => 'devstoreaccount1',
        'account_key' => base64_encode('secret'),
        'container' => 'backups',
        'endpoint' => 'http://azurite:10000/devstoreaccount1',
    ]);

    expect($filesystem)->toBeInstanceOf(Filesystem::class);
});

test('get accepts the root key as an alias for prefix', function () {
    $filesystem = (new AzureFilesystem)->get([
        'account_name' => 'myaccount',
        'account_key' => base64_encode('secret'),
        'container' => 'backups',
        'root' => 'from-backup-config/',
    ]);

    expect($filesystem)->toBeInstanceOf(Filesystem::class);
});
