<?php

namespace App\Services\Backup\Filesystems;

use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;

class FtpFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return strtolower($type ?? '') === 'ftp';
    }

    /**
     * @param  array{host: string, username: string, password: string, port?: int, root?: string, ssl?: bool, passive?: bool, timeout?: int}  $config
     */
    public function get(array $config): Filesystem
    {
        $options = FtpConnectionOptions::fromArray([
            'host' => $config['host'],
            'username' => $config['username'],
            'password' => $config['password'],
            'root' => $config['root'] ?? '/',
            'port' => (int) ($config['port'] ?? 21),
            'ssl' => (bool) ($config['ssl'] ?? false),
            'passive' => (bool) ($config['passive'] ?? true),
            'timeout' => (int) ($config['timeout'] ?? 90),
        ]);

        $adapter = new FtpAdapter($options);

        return new Filesystem($adapter);
    }
}
