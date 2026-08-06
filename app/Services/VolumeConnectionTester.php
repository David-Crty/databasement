<?php

namespace App\Services;

use App\Models\Volume;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\Filesystems\FilesystemProvider;
use League\Flysystem\Filesystem;

readonly class VolumeConnectionTester
{
    public function __construct(
        private FilesystemProvider $filesystemProvider
    ) {}

    /**
     * Test if a volume is accessible by creating and deleting a test file.
     *
     * @return array{success: bool, message: string}
     */
    public function test(Volume $volume): array
    {
        return $this->probe(fn (): Filesystem => $this->filesystemProvider->getForVolume($volume));
    }

    /**
     * Same probe, driven by an already-decrypted config so it can run on an
     * agent, which has no access to the app database.
     *
     * @return array{success: bool, message: string}
     */
    public function testConfig(VolumeConfig $config): array
    {
        return $this->probe(fn (): Filesystem => $this->filesystemProvider->getForVolumeConfig($config));
    }

    /**
     * Write, read back and delete a probe file. Resolving the filesystem is
     * part of the attempt, so an unsupported type or bad config is reported as
     * a failed test rather than thrown.
     *
     * @param  \Closure(): Filesystem  $resolveFilesystem
     * @return array{success: bool, message: string}
     */
    private function probe(\Closure $resolveFilesystem): array
    {
        $testFilename = '.databasement-test-'.uniqid();
        $testContent = 'test-'.uniqid();

        try {
            $filesystem = $resolveFilesystem();

            // Try to write test file
            $filesystem->write($testFilename, $testContent);

            // Try to read test file
            $retrieved = $filesystem->read($testFilename);
            if ($retrieved !== $testContent) {
                $filesystem->delete($testFilename);

                return [
                    'success' => false,
                    'message' => 'Failed to verify test file content.',
                ];
            }

            // Delete test file
            $filesystem->delete($testFilename);

            return [
                'success' => true,
                // Translated so the local probe and the agent-run one, which
                // reports this same outcome from the UI, read identically.
                'message' => __('Connection successful!'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $this->formatErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Clean up error messages for better user experience.
     */
    private function formatErrorMessage(string $message): string
    {
        // Remove empty "reason:" suffix from Flysystem errors
        $message = preg_replace('/\s*reason:\s*$/i', '', $message) ?? $message;

        // Trim whitespace and newlines
        return trim($message);
    }
}
