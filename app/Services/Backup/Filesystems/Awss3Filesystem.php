<?php

namespace App\Services\Backup\Filesystems;

use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;

class Awss3Filesystem implements FilesystemInterface
{
    public function handles(?string $type): bool
    {
        return in_array(strtolower($type ?? ''), ['s3', 'awss3']);
    }

    public function get(array $config): Filesystem
    {
        $client = $this->createClient($config);

        // Support both 'root' (from config/backup.php) and 'prefix' (from Volume database)
        $root = $config['root'] ?? $config['prefix'] ?? '';

        return new Filesystem(new AwsS3V3Adapter($client, $config['bucket'], $root));
    }

    /**
     * Generate a presigned URL for downloading a file from S3
     *
     * @param  array<string, mixed>  $config
     */
    public function getPresignedUrl(array $config, string $path, int $expiresInMinutes = 60): string
    {
        // Use a client configured with the public endpoint so the signature is valid
        $client = $this->getClientForPresignedUrls($config);
        $key = $this->buildKeyPath($config, $path);

        $command = $client->getCommand('GetObject', [
            'Bucket' => $config['bucket'],
            'Key' => $key,
        ]);

        $request = $client->createPresignedRequest($command, "+{$expiresInMinutes} minutes");

        return (string) $request->getUri();
    }

    /**
     * Build the full S3 key path including prefix
     *
     * @param  array<string, mixed>  $config
     */
    public function buildKeyPath(array $config, string $path): string
    {
        $prefix = $config['root'] ?? $config['prefix'] ?? '';

        return $prefix ? rtrim($prefix, '/').'/'.ltrim($path, '/') : $path;
    }

    /**
     * Get S3 client configured with public endpoint for generating presigned URLs
     *
     * When using S3-compatible storage in Docker, the internal endpoint (e.g., http://minio:9000)
     * differs from the public endpoint (e.g., http://localhost:9000). Presigned URLs must be
     * generated with the public endpoint so the signature matches when accessed from the browser.
     *
     * @param  array<string, mixed>  $config
     */
    protected function getClientForPresignedUrls(array $config): S3Client
    {
        $publicEndpoint = $config['public_endpoint'] ?? null;

        // If no public endpoint configured, use the regular client
        if (empty($publicEndpoint)) {
            return $this->createClient($config);
        }

        return $this->createClient($config, $publicEndpoint);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createClient(array $config, ?string $endpointOverride = null): S3Client
    {
        $clientConfig = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
        ];

        // Use IAM role assumption if role_arn is configured
        if (! empty($config['custom_role_arn'])) {
            $clientConfig['credentials'] = CredentialProvider::memoize($this->createCustomAssumeRoleCredentials($config));
        } elseif (! empty($config['access_key_id']) && ! empty($config['secret_access_key'])) {
            // Use explicit credentials when configured (e.g., for testing or non-AWS environments)
            $clientConfig['credentials'] = [
                'key' => $config['access_key_id'],
                'secret' => $config['secret_access_key'],
            ];
        }

        // Use endpoint override if provided (for presigned URLs), otherwise use configured endpoint
        $endpoint = $endpointOverride ?? $config['custom_endpoint'] ?? null;
        if (! empty($endpoint)) {
            $clientConfig['endpoint'] = $endpoint;
        }

        if (! empty($config['use_path_style_endpoint'])) {
            $clientConfig['use_path_style_endpoint'] = true;
        }

        return new S3Client($clientConfig);
    }

    /**
     * Create credentials provider using IAM role assumption via STS
     *
     * @param  array<string, mixed>  $config
     */
    protected function createCustomAssumeRoleCredentials(array $config): AssumeRoleCredentialProvider
    {
        $stsConfig = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
        ];

        if (! empty($config['access_key_id']) && ! empty($config['secret_access_key'])) {
            $stsConfig['credentials'] = [
                'key' => $config['access_key_id'],
                'secret' => $config['secret_access_key'],
            ];
        }

        if (! empty($config['sts_endpoint'])) {
            $stsConfig['endpoint'] = $config['sts_endpoint'];
        }

        $stsClient = new StsClient($stsConfig);

        return new AssumeRoleCredentialProvider([
            'client' => $stsClient,
            'assume_role_params' => [
                'RoleArn' => $config['custom_role_arn'],
                'RoleSessionName' => $config['role_session_name'] ?? 'databasement',
            ],
        ]);
    }
}
