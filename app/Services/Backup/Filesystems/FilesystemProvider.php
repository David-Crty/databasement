<?php

namespace App\Services\Backup\Filesystems;

use App\Exceptions\Backup\FilesystemException;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\DTO\VolumeConfig;
use League\Flysystem\Filesystem;

class FilesystemProvider
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var FilesystemInterface[] */
    private array $filesystems = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function add(FilesystemInterface $filesystem): void
    {
        $this->filesystems[] = $filesystem;
    }

    /**
     * Get a filesystem instance for a Volume (uses database config)
     */
    public function getForVolume(Volume $volume): Filesystem
    {
        foreach ($this->filesystems as $filesystem) {
            if ($filesystem->handles($volume->type)) {
                // Use decrypted config for sensitive fields (passwords, etc.)
                return $filesystem->get($volume->getDecryptedConfig());
            }
        }

        throw new FilesystemException("The requested filesystem type {$volume->type} is not currently supported.");
    }

    /**
     * Get a filesystem instance by config name (uses config/backup.php)
     *
     * @deprecated Use getForVolume() when you have a Volume object
     */
    public function get(string $name): Filesystem
    {
        $type = $this->getConfig($name, 'type');

        foreach ($this->filesystems as $filesystem) {
            if ($filesystem->handles($type)) {
                return $filesystem->get($this->config[$name] ?? []);
            }
        }

        throw new FilesystemException("The requested filesystem type {$type} is not currently supported.");
    }

    public function getConfig(string $name, ?string $key = null): mixed
    {
        if ($key === null) {
            return $this->config[$name] ?? null;
        }

        return $this->config[$name][$key] ?? null;
    }

    /**
     * @return string[]
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->config);
    }

    public function transfer(Volume $volume, string $source, string $destination): void
    {
        $filesystem = $this->getForVolume($volume);
        $this->writeToFilesystem($filesystem, $source, $destination);
    }

    public function download(Snapshot $snapshot, string $destination): void
    {
        $filesystem = $this->getForVolume($snapshot->volume);
        $this->readFromFilesystem($filesystem, $snapshot->filename, $destination);
    }

    /**
     * Get a filesystem instance for a VolumeConfig DTO (config already decrypted).
     */
    public function getForVolumeConfig(VolumeConfig $config): Filesystem
    {
        foreach ($this->filesystems as $filesystem) {
            if ($filesystem->handles($config->type)) {
                return $filesystem->get($config->config);
            }
        }

        throw new FilesystemException("The requested filesystem type {$config->type} is not currently supported.");
    }

    public function transferFromConfig(VolumeConfig $config, string $source, string $destination): void
    {
        $filesystem = $this->getForVolumeConfig($config);
        $this->writeToFilesystem($filesystem, $source, $destination);
    }

    public function downloadFromConfig(VolumeConfig $config, string $remoteFilename, string $destination): void
    {
        $filesystem = $this->getForVolumeConfig($config);
        $this->readFromFilesystem($filesystem, $remoteFilename, $destination);
    }

    private function writeToFilesystem(Filesystem $filesystem, string $source, string $destination): void
    {
        $stream = fopen($source, 'r');
        if ($stream === false) {
            throw new FilesystemException("Failed to open file: {$source}");
        }

        try {
            $filesystem->writeStream($destination, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function readFromFilesystem(Filesystem $filesystem, string $remoteFilename, string $destination): void
    {
        $stream = $filesystem->readStream($remoteFilename);
        $localStream = fopen($destination, 'w');

        if ($localStream === false) {
            throw new FilesystemException("Failed to open destination file: {$destination}");
        }

        try {
            stream_copy_to_stream($stream, $localStream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            fclose($localStream);
        }
    }
}
