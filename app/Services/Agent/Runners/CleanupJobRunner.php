<?php

namespace App\Services\Agent\Runners;

use App\Enums\AgentJobType;
use App\Services\Agent\AgentApiClient;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use Closure;
use Throwable;

class CleanupJobRunner implements AgentJobRunner
{
    public function __construct(
        private FilesystemProvider $filesystemProvider,
    ) {}

    public function type(): AgentJobType
    {
        return AgentJobType::Cleanup;
    }

    /**
     * Delete the snapshot's archive from each volume of the payload, and
     * report the outcome per copy: a file already absent counts as removed.
     */
    public function run(array $job, AgentApiClient $client, Closure $log): void
    {
        try {
            $payload = $job['payload'];
            $filename = $payload['filename'];

            $log("Processing cleanup job {$job['id']}: {$payload['server_name']} / {$filename}");

            $results = [];
            $errors = [];

            foreach ($payload['files'] as $file) {
                $volume = VolumeConfig::fromPayload($file['volume']);

                try {
                    FilesystemSupport::deleteFile($this->filesystemProvider->getForVolumeConfig($volume), $filename);
                    $results[] = ['id' => $file['id'], 'error' => null];
                } catch (Throwable $e) {
                    $results[] = ['id' => $file['id'], 'error' => $e->getMessage()];
                    $errors[] = "{$volume->name}: {$e->getMessage()}";
                    $log("Could not delete {$filename} from {$volume->name}: {$e->getMessage()}", 'error');
                }
            }

            if ($errors === []) {
                $client->ack($job['id'], ['files' => $results]);
                $log("Cleanup completed: {$filename}");

                return;
            }

            $client->fail($job['id'], 'Could not delete the file from every volume ('.implode('; ', $errors).').', result: ['files' => $results]);
        } catch (Throwable $e) {
            $log("Cleanup failed: {$e->getMessage()}", 'error');
            $client->fail($job['id'], $e->getMessage());
        }
    }
}
