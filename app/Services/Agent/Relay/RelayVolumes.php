<?php

namespace App\Services\Agent\Relay;

use App\Enums\AgentJobType;
use App\Enums\VolumeType;
use App\Models\Agent;
use App\Models\AgentJob;

/**
 * Decides which volumes of an agent job the agent reaches through this server
 * instead of directly, and what it may do with them there.
 *
 * A relayed volume reaches the agent as a `relay` volume carrying no storage
 * credentials: the agent's RelayFilesystem sends each read, write and delete
 * to the job's file endpoints, and this server runs it on the real volume.
 * The stored payload keeps the real volumes, so access is always checked
 * against what the job was created for.
 */
class RelayVolumes
{
    public const string VOLUME_TYPE = 'relay';

    /**
     * What an agent sends in `capabilities` when claiming once it can run
     * relay volumes.
     */
    public const string CAPABILITY = 'relay';

    public const string READ = 'read';

    public const string WRITE = 'write';

    public const string DELETE = 'delete';

    public const string EXISTS = 'exists';

    /**
     * Whether the agent reaches a volume of this type through the server:
     * every volume when it is set to, and the app's local volumes always,
     * since they are on this server's disk.
     */
    public static function relays(Agent $agent, string $volumeType): bool
    {
        return $agent->transfer_via_server || $volumeType === VolumeType::LOCAL->value;
    }

    /**
     * The job's payload as the agent receives it, with each volume it must
     * reach through the server swapped for a relay volume.
     *
     * @return array<string, mixed>
     *
     * @throws RelayNotSupportedException When a volume must be relayed and the agent cannot
     */
    public function payloadFor(AgentJob $job, Agent $agent, bool $agentSupportsRelay): array
    {
        $payload = $job->payload;
        $relayed = false;

        $relay = function (array $volume) use ($job, $agent, &$relayed): array {
            if (($volume['id'] ?? null) === null || ! self::relays($agent, $volume['type'] ?? '')) {
                return $volume;
            }

            $relayed = true;

            return [
                'id' => $volume['id'],
                'type' => self::VOLUME_TYPE,
                'name' => $volume['name'] ?? 'Remote Volume',
                'config' => ['job_id' => $job->id, 'volume_id' => $volume['id']],
            ];
        };

        if (isset($payload['volume'])) {
            $payload['volume'] = $relay($payload['volume']);
        }

        if (isset($payload['volumes'])) {
            $payload['volumes'] = array_map($relay, $payload['volumes']);
        }

        if (isset($payload['files'])) {
            $payload['files'] = array_map(fn (array $file) => [...$file, 'volume' => $relay($file['volume'])], $payload['files']);
        }

        if ($relayed && ! $agentSupportsRelay) {
            throw new RelayNotSupportedException('This agent must transfer files through the server but its version cannot. Update the agent.');
        }

        return $payload;
    }

    /**
     * Whether the job lets its agent run this operation on this file of this
     * volume: a backup writes its archive to its target volumes (and deletes
     * it again when the backup fails), a restore reads its source copy, and a
     * cleanup deletes the snapshot's copies.
     */
    public function allows(AgentJob $job, string $volumeId, string $operation, string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            return false;
        }

        $payload = $job->payload;

        [$volumeIds, $operations, $pathAllowed] = match ($job->type) {
            AgentJobType::Backup => [
                array_column($payload['volumes'] ?? (isset($payload['volume']) ? [$payload['volume']] : []), 'id'),
                [self::WRITE, self::DELETE, self::EXISTS],
                $this->isBackupArchivePath($payload['backup_path'] ?? '', $path),
            ],
            AgentJobType::Restore => [
                [$payload['volume']['id'] ?? null],
                [self::READ, self::EXISTS],
                $path === ($payload['snapshot']['filename'] ?? null),
            ],
            AgentJobType::Cleanup => [
                array_map(fn (array $file) => $file['volume']['id'] ?? null, $payload['files'] ?? []),
                [self::DELETE, self::EXISTS],
                $path === ($payload['filename'] ?? null),
            ],
            AgentJobType::Discover => [[], [], false],
        };

        return $pathAllowed
            && in_array($operation, $operations, true)
            && in_array($volumeId, $volumeIds, true);
    }

    /**
     * A backup names its archive itself, so only its directory is checked:
     * the archive sits right in the backup's path, or at the volume root
     * when it has none.
     */
    private function isBackupArchivePath(string $backupPath, string $path): bool
    {
        $directory = trim($backupPath, '/');

        return dirname($path) === ($directory === '' ? '.' : $directory);
    }
}
