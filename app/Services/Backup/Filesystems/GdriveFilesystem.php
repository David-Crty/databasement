<?php

namespace App\Services\Backup\Filesystems;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class GdriveFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return strtolower($type ?? '') === 'gdrive';
    }

    /**
     * @param  array{client_id: string, client_secret: string, refresh_token: string, folder_id?: string}  $config
     */
    public function get(array $config): Filesystem
    {
        $client = new GoogleClient;
        $client->setClientId($config['client_id']);
        $client->setClientSecret($config['client_secret']);
        $client->refreshToken($config['refresh_token']);

        $service = new GoogleDrive($client);

        $root = $config['folder_id'] ?? 'root';

        return new Filesystem(new GoogleDriveAdapter($service, $root));
    }
}
