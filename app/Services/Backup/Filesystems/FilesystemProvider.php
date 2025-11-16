<?php

namespace App\Services\Backup\Filesystems;

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
}
