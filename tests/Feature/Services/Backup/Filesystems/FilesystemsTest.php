<?php

use App\Services\Backup\Filesystems\Awss3Filesystem;
use App\Services\Backup\Filesystems\FilesystemInterface;
use App\Services\Backup\Filesystems\FtpFilesystem;
use App\Services\Backup\Filesystems\LocalFilesystem;
use App\Services\Backup\Filesystems\SftpFilesystem;
use League\Flysystem\Filesystem;

dataset('filesystem implementations', function () {
    return [
        'local' => [
            LocalFilesystem::class,
            'local',
            ['path' => sys_get_temp_dir()],
        ],
        'sftp' => [
            SftpFilesystem::class,
            'sftp',
            [
                'host' => 'example.com',
                'username' => 'user',
                'password' => 'pass',
            ],
        ],
        'ftp' => [
            FtpFilesystem::class,
            'ftp',
            [
                'host' => 'example.com',
                'username' => 'user',
                'password' => 'pass',
            ],
        ],
        'awss3' => [
            Awss3Filesystem::class,
            'awss3',
            [
                'bucket' => 'test-bucket',
            ],
        ],
    ];
});

test('handles() returns true for matching type', function (string $class, string $type) {
    /** @var FilesystemInterface $filesystem */
    $filesystem = new $class;

    expect($filesystem->handles($type))->toBeTrue()
        ->and($filesystem->handles(strtoupper($type)))->toBeTrue()
        ->and($filesystem->handles(ucfirst($type)))->toBeTrue();
})->with('filesystem implementations');

test('get() returns Filesystem instance', function (string $class, string $type, array $config) {

    /** @var FilesystemInterface $filesystem */
    $filesystem = new $class;
    $result = $filesystem->get($config);

    expect($result)->toBeInstanceOf(Filesystem::class);
})->with('filesystem implementations');
