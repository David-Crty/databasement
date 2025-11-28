<?php

namespace App\Services\Backup\Filesystems;

use App\Models\Snapshot;
use App\Models\Volume;

class FilesystemProvider
{
    private array $config;

    private array $filesystems = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function add(FilesystemInterface $filesystem): void
    {
        $this->filesystems[] = $filesystem;
    }

    public function get($name)
    {
        $type = $this->getConfig($name, 'type');

        foreach ($this->filesystems as $filesystem) {
            if ($filesystem->handles($type)) {
                return $filesystem->get($this->config[$name] ?? []);
            }
        }

        throw new \Exception("The requested filesystem type {$type} is not currently supported.");
    }

    public function getConfig($name, $key = null)
    {
        if ($key === null) {
            return $this->config[$name] ?? null;
        }

        return $this->config[$name][$key] ?? null;
    }

    public function getAvailableProviders()
    {
        return array_keys($this->config);
    }

    public function transfert(Volume $volume, string $source, string $destination): void
    {
        $filesystem = $this->get($volume->type);
        $stream = fopen($source, 'r');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open file: {$source}");
        }

        try {
            $filesystem->writeStream($destination, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

    }

    public function download(Snapshot $snapshot, $destination): void
    {
        $filesystem = $this->get($snapshot->volume->type);
        $stream = $filesystem->readStream($snapshot->path);
        $localStream = fopen($destination, 'w');

        if ($stream === false || $localStream === false) {
            throw new \RuntimeException('Failed to open streams for download');
        }

        try {
            stream_copy_to_stream($stream, $localStream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($localStream)) {
                fclose($localStream);
            }
        }
    }
}
