<?php

namespace App\Services\Backup\Filesystems\Smb;

use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\IShare;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use Throwable;

/**
 * Flysystem adapter backed by an icewind/smb share.
 *
 * Only the operations exercised by the backup/restore flow are heavily used
 * (write/read/delete/list/createDirectory); the rest are implemented for
 * interface completeness.
 */
class IcewindSmbAdapter implements FilesystemAdapter
{
    private PathPrefixer $prefixer;

    public function __construct(
        private readonly IShare $share,
        string $root = '/',
    ) {
        $this->prefixer = new PathPrefixer(trim($root, '/'));
    }

    public function fileExists(string $path): bool
    {
        try {
            return ! $this->share->stat($this->location($path))->isDirectory();
        } catch (NotFoundException) {
            return false;
        } catch (Throwable $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            return $this->share->stat($this->location($path))->isDirectory();
        } catch (NotFoundException) {
            return false;
        } catch (Throwable $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->location($path);
        $this->ensureParentDirectory($location);

        try {
            $target = $this->share->write($location);
            stream_copy_to_stream($contents, $target);
            fclose($target);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        try {
            return $this->share->read($this->location($path));
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->share->del($this->location($path));
        } catch (NotFoundException) {
            // Already gone — nothing to do.
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $location = $this->location($path);

        try {
            $this->deleteContentsRecursively($location);
            $this->share->rmdir($location);
        } catch (NotFoundException) {
            // Already gone.
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->ensureDirectory($this->location($path));
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Visibility is governed by the SMB share's own permissions.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::mimeType($path, 'SMB does not expose mime types.');
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            $info = $this->share->stat($this->location($path));

            return new FileAttributes($path, null, null, $info->getMTime());
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            $info = $this->share->stat($this->location($path));

            if ($info->isDirectory()) {
                throw UnableToRetrieveMetadata::fileSize($path, 'Path is a directory.');
            }

            return new FileAttributes($path, $info->getSize());
        } catch (UnableToRetrieveMetadata $e) {
            throw $e;
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $location = $this->location($path);

        try {
            $items = $this->share->dir($location === '' ? '/' : $location);
        } catch (NotFoundException) {
            return;
        }

        foreach ($items as $item) {
            $childLocation = $this->joinPath($location, $item->getName());
            $relativePath = $this->prefixer->stripPrefix($childLocation);

            if ($item->isDirectory()) {
                yield new DirectoryAttributes($relativePath, null, $item->getMTime());

                if ($deep) {
                    yield from $this->listContents($relativePath, true);
                }
            } else {
                yield new FileAttributes($relativePath, $item->getSize(), null, $item->getMTime());
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $to = $this->location($destination);

        try {
            $this->ensureParentDirectory($to);
            $this->share->rename($this->location($source), $to);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $stream = $this->readStream($source);
            $this->writeStream($destination, $stream, $config);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Prefix a Flysystem path with the configured root and drop trailing slashes.
     */
    private function location(string $path): string
    {
        return rtrim($this->prefixer->prefixPath($path), '/');
    }

    private function ensureParentDirectory(string $location): void
    {
        $parent = $this->dirname($location);

        if ($parent !== '') {
            $this->ensureDirectory($parent);
        }
    }

    private function ensureDirectory(string $location): void
    {
        $location = trim($location, '/');

        if ($location === '') {
            return;
        }

        $current = '';
        foreach (explode('/', $location) as $segment) {
            $current = $current === '' ? $segment : $current.'/'.$segment;

            try {
                $this->share->mkdir($current);
            } catch (AlreadyExistsException) {
                // Directory already present — keep going.
            }
        }
    }

    private function deleteContentsRecursively(string $location): void
    {
        foreach ($this->share->dir($location === '' ? '/' : $location) as $item) {
            $child = $this->joinPath($location, $item->getName());

            if ($item->isDirectory()) {
                $this->deleteContentsRecursively($child);
                $this->share->rmdir($child);
            } else {
                $this->share->del($child);
            }
        }
    }

    private function joinPath(string $dir, string $name): string
    {
        $dir = trim($dir, '/');

        return $dir === '' ? $name : $dir.'/'.$name;
    }

    private function dirname(string $location): string
    {
        $location = trim($location, '/');
        $pos = strrpos($location, '/');

        return $pos === false ? '' : substr($location, 0, $pos);
    }
}
