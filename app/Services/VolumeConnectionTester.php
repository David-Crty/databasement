<?php

namespace App\Services;

readonly class VolumeConnectionTester
{
    /**
     * Test if a volume is accessible by creating and deleting a test file.
     *
     * @param  array{type: string, path?: string, bucket?: string, prefix?: string, key?: string, secret?: string, region?: string}  $config
     * @return array{success: bool, message: string}
     */
    public function test(array $config): array
    {
        $type = $config['type'];

        try {
            return match ($type) {
                'local' => $this->testLocal($config),
                's3' => $this->testS3($config),
                default => [
                    'success' => false,
                    'message' => "Unsupported volume type: {$type}",
                ],
            };
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test local filesystem access.
     *
     * @param  array{path?: string}  $config
     * @return array{success: bool, message: string}
     */
    private function testLocal(array $config): array
    {
        $path = $config['path'] ?? '';

        if (empty($path)) {
            return [
                'success' => false,
                'message' => 'Path is required for local volumes.',
            ];
        }

        // Try to create directory if it doesn't exist
        if (! is_dir($path)) {
            if (! @mkdir($path, 0755, true)) {
                return [
                    'success' => false,
                    'message' => "Failed to create directory: {$path}",
                ];
            }
        }

        // Check if directory is writable
        if (! is_writable($path)) {
            return [
                'success' => false,
                'message' => "Directory is not writable: {$path}",
            ];
        }

        // Try to create and delete a test file
        $testFile = $path.'/.backup-manager-test-'.uniqid();

        try {
            // Create test file
            $written = file_put_contents($testFile, 'test');
            if ($written === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to write test file to directory.',
                ];
            }

            // Read test file
            $content = file_get_contents($testFile);
            if ($content !== 'test') {
                unlink($testFile);

                return [
                    'success' => false,
                    'message' => 'Failed to read test file from directory.',
                ];
            }

            // Delete test file
            if (! unlink($testFile)) {
                return [
                    'success' => false,
                    'message' => 'Failed to delete test file from directory.',
                ];
            }

            return [
                'success' => true,
                'message' => 'Connection successful! Directory is readable and writable.',
            ];
        } catch (\Throwable $e) {
            // Clean up if test file was created
            if (file_exists($testFile)) {
                @unlink($testFile);
            }

            return [
                'success' => false,
                'message' => 'Failed to access directory: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Test S3 bucket access.
     *
     * @param  array{bucket?: string, prefix?: string, key?: string, secret?: string, region?: string}  $config
     * @return array{success: bool, message: string}
     */
    private function testS3(array $config): array
    {
        $bucket = $config['bucket'] ?? '';

        if (empty($bucket)) {
            return [
                'success' => false,
                'message' => 'Bucket name is required for S3 volumes.',
            ];
        }

        // For S3, we need AWS credentials - check if they're configured
        $key = $config['key'] ?? config('services.aws.key');
        $secret = $config['secret'] ?? config('services.aws.secret');
        $region = $config['region'] ?? config('services.aws.region', 'us-east-1');

        if (empty($key) || empty($secret)) {
            return [
                'success' => false,
                'message' => 'AWS credentials are not configured. Please set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in your environment.',
            ];
        }

        try {
            // Build S3 config for the filesystem
            $filesystemConfig = [
                'bucket' => $bucket,
                'prefix' => $config['prefix'] ?? '',
                'key' => $key,
                'secret' => $secret,
                'region' => $region,
            ];

            // Create a temporary Volume-like object to test
            $testFilename = '.backup-manager-test-'.uniqid();
            $testContent = 'test-'.uniqid();

            // Use AWS SDK directly for testing
            $client = new \Aws\S3\S3Client([
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret,
                ],
                'region' => $region,
                'version' => 'latest',
            ]);

            $prefix = $config['prefix'] ?? '';
            $fullPath = rtrim($prefix, '/').'/'.$testFilename;
            $fullPath = ltrim($fullPath, '/');

            // Try to put object
            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $fullPath,
                'Body' => $testContent,
            ]);

            // Try to get object
            $result = $client->getObject([
                'Bucket' => $bucket,
                'Key' => $fullPath,
            ]);

            $retrieved = (string) $result['Body'];
            if ($retrieved !== $testContent) {
                // Clean up
                $client->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $fullPath,
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to verify test file content from S3.',
                ];
            }

            // Delete test object
            $client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $fullPath,
            ]);

            return [
                'success' => true,
                'message' => 'Connection successful! S3 bucket is accessible.',
            ];
        } catch (\Aws\Exception\AwsException $e) {
            return [
                'success' => false,
                'message' => 'S3 error: '.$e->getAwsErrorMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to connect to S3: '.$e->getMessage(),
            ];
        }
    }
}
