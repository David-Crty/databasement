<?php

namespace App\Services\Backup\Filesystems;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return strtolower($type ?? '') === 'azure';
    }

    /**
     * @param  array{account_name: string, account_key: string, container: string, prefix?: string, endpoint_suffix?: string, root?: string}  $config
     */
    public function get(array $config): Filesystem
    {
        $client = BlobRestProxy::createBlobService($this->buildConnectionString($config));

        // Support both 'root' (from config/backup.php) and 'prefix' (from Volume database)
        $prefix = $config['root'] ?? $config['prefix'] ?? '';

        return new Filesystem(new AzureBlobStorageAdapter($client, $config['container'], $prefix));
    }

    /**
     * @param  array{account_name: string, account_key: string, endpoint_suffix?: string, endpoint?: string}  $config
     */
    protected function buildConnectionString(array $config): string
    {
        $parts = [
            'AccountName='.$config['account_name'],
            'AccountKey='.$config['account_key'],
        ];

        // A custom blob endpoint (Azurite, sovereign clouds, self-hosted gateways)
        // overrides the account-derived *.blob.{suffix} URL. Protocol follows its scheme.
        if (! empty($config['endpoint'])) {
            $scheme = str_starts_with($config['endpoint'], 'http://') ? 'http' : 'https';
            array_unshift($parts, 'DefaultEndpointsProtocol='.$scheme);
            $parts[] = 'BlobEndpoint='.$config['endpoint'];
        } else {
            $endpointSuffix = ! empty($config['endpoint_suffix']) ? $config['endpoint_suffix'] : 'core.windows.net';
            array_unshift($parts, 'DefaultEndpointsProtocol=https');
            $parts[] = 'EndpointSuffix='.$endpointSuffix;
        }

        return implode(';', $parts);
    }
}
