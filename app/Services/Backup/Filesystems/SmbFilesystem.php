<?php

namespace App\Services\Backup\Filesystems;

use App\Services\Backup\Filesystems\Smb\IcewindSmbAdapter;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\ServerFactory;
use League\Flysystem\Filesystem;

class SmbFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return strtolower($type ?? '') === 'smb';
    }

    /**
     * @param  array{host: string, share: string, username: string, password?: string|null, domain?: string|null, root?: string}  $config
     */
    public function get(array $config): Filesystem
    {
        $domain = $config['domain'] ?? null;
        if ($domain === '') {
            $domain = null;
        }

        $auth = new BasicAuth(
            $config['username'] ?? '',
            $domain,
            $config['password'] ?? '',
        );

        $share = (new ServerFactory)
            ->createServer($config['host'], $auth)
            ->getShare($config['share']);

        return new Filesystem(new IcewindSmbAdapter($share, $config['root'] ?? '/'));
    }
}
