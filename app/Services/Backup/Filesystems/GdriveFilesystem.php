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

        $options = ['useDisplayPaths' => true];

        $folderId = ! empty($config['folder_id']) ? $config['folder_id'] : null;

        if ($folderId !== null) {
            $options['sharedFolderId'] = $folderId;
        }

        $adapter = new GoogleDriveAdapter($service, null, $options);

        return new Filesystem($adapter);
    }
}
