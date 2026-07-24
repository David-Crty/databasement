<?php

namespace App\Services\Backup\Filesystems;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;

class LocalFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return strtolower($type ?? '') === 'local';
    }

    /**
     * @param  array{root?: string, path?: string}  $config
     */
    public function get(array $config): Filesystem
    {
        // Support both 'root' (from config/backup.php) and 'path' (from Volume database)
        $root = $config['root'] ?? $config['path'] ?? null;

        if ($root === null) {
            throw new \InvalidArgumentException('Local filesystem requires either "root" or "path" in config');
        }

        // The queue worker may run as a different user (often root) than the web
        // server, which also needs to read snapshots to serve downloads. Default
        // everything to public visibility (0755 dirs / 0644 files) so directories
        // created during a backup stay traversable by the web process.
        $adapter = new LocalFilesystemAdapter(
            $root,
            new PortableVisibilityConverter(defaultForDirectories: Visibility::PUBLIC)
        );

        return new Filesystem($adapter, ['visibility' => Visibility::PUBLIC]);
    }
}
