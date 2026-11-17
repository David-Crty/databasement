<?php

namespace App\Services\Backup\Filesystems;

use App\Exceptions\Backup\FilesystemException;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use GuzzleHttp\ClientInterface;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class GdriveFilesystem implements FilesystemInterface
{
    public function __construct(private ?ClientInterface $httpClient = null) {}

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
        if ($this->httpClient !== null) {
            $client->setHttpClient($this->httpClient);
        }
        $client->setClientId(trim($config['client_id']));
        $client->setClientSecret(trim($config['client_secret']));

        $token = $client->fetchAccessTokenWithRefreshToken(trim($config['refresh_token']));

        if (isset($token['error'])) {
            $reason = isset($token['error_description'])
                ? "{$token['error']}: {$token['error_description']}"
                : $token['error'];

            throw new FilesystemException("Google Drive authentication failed ({$reason})");
        }

        $options = ['useDisplayPaths' => true];

        $folderId = trim($config['folder_id'] ?? '');

        if ($folderId !== '') {
            $options['sharedFolderId'] = $folderId;
        }

        return new Filesystem(new GoogleDriveAdapter(new GoogleDrive($client), null, $options));
    }
}
