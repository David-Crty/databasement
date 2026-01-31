<?php

namespace App\Services\Backup\Filesystems;

use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

class SftpFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return strtolower($type ?? '') === 'sftp';
    }

    /**
     * @param  array{host: string, username: string, password: string, port?: int, root?: string, timeout?: int}  $config
     */
    public function get(array $config): Filesystem
    {
        $provider = new SftpConnectionProvider(
            host: $config['host'],
            username: $config['username'],
            password: $config['password'],
            port: (int) ($config['port'] ?? 22),
            timeout: (int) ($config['timeout'] ?? 10),
        );

        $root = $config['root'] ?? '/';

        $adapter = new SftpAdapter($provider, $root);

        return new Filesystem($adapter);
    }
}
