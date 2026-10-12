<?php

namespace App\Services\Backup\Filesystems;

use App\Services\Agent\AgentApiClient;
use App\Services\Agent\Relay\RelayFilesystemAdapter;
use App\Services\Agent\Relay\RelayVolumes;
use League\Flysystem\Filesystem;
use RuntimeException;

/**
 * A volume an agent reaches through the server it reports to. Only runs on an
 * agent, which binds its AgentApiClient while it polls.
 */
class RelayFilesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return $type === RelayVolumes::VOLUME_TYPE;
    }

    /**
     * @param  array{job_id?: string, volume_id?: string}  $config
     */
    public function get(array $config): Filesystem
    {
        if (! isset($config['job_id'], $config['volume_id'])) {
            throw new \InvalidArgumentException('Relay filesystem requires "job_id" and "volume_id" in config');
        }

        if (! app()->bound(AgentApiClient::class)) {
            throw new RuntimeException('Relay volumes can only be used by a running agent.');
        }

        return new Filesystem(new RelayFilesystemAdapter(
            app(AgentApiClient::class),
            $config['job_id'],
            $config['volume_id'],
            (int) config('agent.relay_chunk_bytes'),
        ));
    }
}
