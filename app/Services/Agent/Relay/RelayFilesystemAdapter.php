<?php

namespace App\Services\Agent\Relay;

use App\Services\Agent\AgentApiClient;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

/**
 * Agent-side adapter for a volume reached through the server: each operation
 * is a call to the job's file endpoints, which run it on the real volume.
 *
 * It covers what backups, restores and cleanups do with a volume. The server
 * prunes the directories a delete leaves empty, so directory operations are
 * no-ops and listings are empty.
 */
class RelayFilesystemAdapter implements FilesystemAdapter
{
    public function __construct(
        private AgentApiClient $client,
        private string $jobId,
        private string $volumeId,
        private int $chunkBytes,
    ) {}

    public function fileExists(string $path): bool
    {
        return $this->client->fileExists($this->jobId, $this->volumeId, $path);
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw UnableToWriteFile::atLocation($path, 'Could not buffer the contents.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $offset = 0;

            while (! feof($contents)) {
                $chunk = $this->readChunk($contents);

                if ($chunk === '') {
                    break;
                }

                $this->client->uploadChunk($this->jobId, $this->volumeId, $path, $offset, $chunk);
                $offset += strlen($chunk);
            }

            // An empty file still needs its (empty) upload started.
            if ($offset === 0) {
                $this->client->uploadChunk($this->jobId, $this->volumeId, $path, 0, '');
            }

            $this->client->completeUpload($this->jobId, $this->volumeId, $path, $offset);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Download the file to a temporary file and return it opened for reading.
     *
     * @return resource
     */
    public function readStream(string $path)
    {
        $temporary = tempnam(sys_get_temp_dir(), 'relay-');

        if ($temporary === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not create a temporary file.');
        }

        try {
            $this->client->downloadFile($this->jobId, $this->volumeId, $path, $temporary);

            $stream = fopen($temporary, 'rb');
            if ($stream === false) {
                throw UnableToReadFile::fromLocation($path, 'Could not open the downloaded file.');
            }

            return $stream;
        } catch (UnableToReadFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        } finally {
            // The open handle keeps the data readable; the name is not needed.
            @unlink($temporary);
        }
    }

    public function delete(string $path): void
    {
        $this->client->deleteFile($this->jobId, $this->volumeId, $path);
    }

    public function deleteDirectory(string $path): void
    {
        // The server already removed the directories a delete left empty.
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Directories are created on the server as the file is written.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Not supported on relay volumes.');
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Not supported on relay volumes.');
    }

    public function mimeType(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::mimeType($path, 'Not supported on relay volumes.');
    }

    public function lastModified(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::lastModified($path, 'Not supported on relay volumes.');
    }

    public function fileSize(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::fileSize($path, 'Not supported on relay volumes.');
    }

    /**
     * @return iterable<never>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }

    /**
     * Read up to one chunk, looping since a stream (a pipe or a network
     * stream) may return less than asked per read.
     *
     * @param  resource  $stream
     */
    private function readChunk($stream): string
    {
        $chunk = '';

        while (strlen($chunk) < $this->chunkBytes && ! feof($stream)) {
            $data = fread($stream, max(1, $this->chunkBytes - strlen($chunk)));

            if ($data === false) {
                throw new \RuntimeException('Could not read the file being uploaded.');
            }

            $chunk .= $data;
        }

        return $chunk;
    }
}
