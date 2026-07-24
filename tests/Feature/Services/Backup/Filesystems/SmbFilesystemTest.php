<?php

use App\Services\Backup\Filesystems\SmbFilesystem;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\Exception\DependencyException;
use Icewind\SMB\IShare;
use Icewind\SMB\ServerFactory;
use League\Flysystem\Filesystem;

function smbFilesystemWithExposedInternals(): SmbFilesystem
{
    return new class extends SmbFilesystem
    {
        /** @param  array<string, mixed>  $config */
        public function exposeResolveRoot(array $config): string
        {
            return $this->resolveRoot($config);
        }

        public function exposeEnsureRootDirectoryExists(IShare $share, string $root): void
        {
            $this->ensureRootDirectoryExists($share, $root);
        }
    };
}

function smbBackendIsAvailable(): bool
{
    try {
        (new ServerFactory)->createServer('localhost', new BasicAuth('user', null, 'pass'));

        return true;
    } catch (DependencyException) {
        return false;
    }
}

test('handles only the smb type', function () {
    $filesystem = new SmbFilesystem;

    expect($filesystem->handles('smb'))->toBeTrue()
        ->and($filesystem->handles('SMB'))->toBeTrue()
        ->and($filesystem->handles('sftp'))->toBeFalse()
        ->and($filesystem->handles(null))->toBeFalse();
});

test('resolves the root from the root key and trims slashes', function () {
    $filesystem = smbFilesystemWithExposedInternals();

    expect($filesystem->exposeResolveRoot(['root' => '/databasement/']))->toBe('databasement')
        ->and($filesystem->exposeResolveRoot(['root' => 'backups/prod']))->toBe('backups/prod');
});

test('accepts the prefix key as an alias for root', function () {
    $filesystem = smbFilesystemWithExposedInternals();

    expect($filesystem->exposeResolveRoot(['prefix' => '/from-volume/']))->toBe('from-volume')
        ->and($filesystem->exposeResolveRoot(['root' => '/wins', 'prefix' => '/loses']))->toBe('wins')
        ->and($filesystem->exposeResolveRoot([]))->toBe('');
});

test('creates each segment of the root directory on the share', function () {
    $share = Mockery::mock(IShare::class);
    $share->shouldReceive('mkdir')->once()->with('backups');
    $share->shouldReceive('mkdir')->once()->with('backups/databasement');

    smbFilesystemWithExposedInternals()->exposeEnsureRootDirectoryExists($share, 'backups/databasement');
});

test('keeps creating child segments when a parent already exists', function () {
    $share = Mockery::mock(IShare::class);
    $share->shouldReceive('mkdir')->once()->with('backups')->andThrow(new AlreadyExistsException);
    $share->shouldReceive('mkdir')->once()->with('backups/databasement');

    smbFilesystemWithExposedInternals()->exposeEnsureRootDirectoryExists($share, 'backups/databasement');
});

test('does not touch the share when no root is configured', function () {
    $share = Mockery::mock(IShare::class);
    $share->shouldNotReceive('mkdir');

    smbFilesystemWithExposedInternals()->exposeEnsureRootDirectoryExists($share, '');
});

test('get builds a Flysystem filesystem', function () {
    $filesystem = (new SmbFilesystem)->get([
        'host' => 'fileserver.example.com',
        'share' => 'backups',
        'username' => 'backup-user',
        'password' => 'secret',
        'domain' => '',
    ]);

    expect($filesystem)->toBeInstanceOf(Filesystem::class);
})->skip(fn () => ! smbBackendIsAvailable(), 'requires the smbclient extension or CLI');
