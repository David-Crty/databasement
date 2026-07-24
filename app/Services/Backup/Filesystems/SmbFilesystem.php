<?php

namespace App\Services\Backup\Filesystems;

use Icewind\SMB\BasicAuth;
use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\IShare;
use Icewind\SMB\ServerFactory;
use Jerodev\Flysystem\Smb\SmbAdapter;
use League\Flysystem\Filesystem;

class SmbFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return strtolower($type ?? '') === 'smb';
    }

    /**
     * @param  array{host: string, share: string, username: string, password?: string|null, domain?: string|null, root?: string, prefix?: string}  $config
     */
    public function get(array $config): Filesystem
    {
        $domain = $config['domain'] ?? null;
        if ($domain === '') {
            $domain = null;
        }

        $auth = new BasicAuth(
            $config['username'],
            $domain,
            $config['password'] ?? '',
        );

        $share = (new ServerFactory)
            ->createServer($config['host'], $auth)
            ->getShare($config['share']);

        $root = $this->resolveRoot($config);

        // The adapter auto-creates parent directories inside the root, but never
        // the root itself — create it here so fresh volumes are writable.
        $this->ensureRootDirectoryExists($share, $root);

        return new Filesystem(new SmbAdapter($share, $root));
    }

    /**
     * Support both 'root' (from config/backup.php) and 'prefix' (from Volume database).
     *
     * @param  array<string, mixed>  $config
     */
    protected function resolveRoot(array $config): string
    {
        return trim($config['root'] ?? $config['prefix'] ?? '', '/');
    }

    protected function ensureRootDirectoryExists(IShare $share, string $root): void
    {
        if ($root === '') {
            return;
        }

        $current = '';
        foreach (explode('/', $root) as $segment) {
            $current = $current === '' ? $segment : "{$current}/{$segment}";

            try {
                $share->mkdir($current);
            } catch (AlreadyExistsException) {
                // Directory already present — keep going.
            }
        }
    }
}
